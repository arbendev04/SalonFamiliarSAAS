<?php

namespace App\Services\Shifts;

use App\Models\Employee;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\WorkScheduleDay;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Generates concrete Shift + ShiftAssignment rows for an employee across a
 * date range, from their active WorkScheduleTemplate on each date (see
 * .ai/08-SHIFTS.md, flujo 3).
 *
 * When the matching WorkScheduleDay defines a planned break window
 * (break_start_time/break_end_time), a shift_breaks row is auto-created
 * alongside the shift. When it doesn't, generation behaves exactly as
 * before — no break is created, and one can still be added manually via the
 * shift_breaks endpoint.
 *
 * Re-running this over an already-generated range is safe: a date already
 * covered by a non-cancelled assignment for the employee is skipped rather
 * than duplicated or rejected.
 */
class ShiftGenerator
{
    /**
     * @return Collection<int, Shift>
     */
    public function generate(Employee $employee, Carbon $startDate, Carbon $endDate): Collection
    {
        $generated = collect();

        for ($date = $startDate->copy(); $date->lte($endDate); $date->addDay()) {
            $shift = $this->generateForDate($employee, $date->copy());

            if ($shift) {
                $generated->push($shift);
            }
        }

        return $generated;
    }

    private function generateForDate(Employee $employee, Carbon $date): ?Shift
    {
        $schedule = $employee->activeScheduleAt($date);

        if (! $schedule) {
            return null;
        }

        $day = WorkScheduleDay::query()
            ->where('template_id', $schedule->template_id)
            ->where('day_of_week', $date->dayOfWeek)
            ->first();

        if (! $day) {
            return null;
        }

        [$startDatetime, $endDatetime] = $this->resolveDatetimes($date, $day);

        if (ShiftAssignment::overlapsForEmployee($employee->id, $startDatetime->toDateTimeString(), $endDatetime->toDateTimeString())) {
            return null;
        }

        $shift = Shift::create([
            'company_id' => $employee->company_id,
            'branch_id' => $employee->branch_id,
            'template_id' => $schedule->template_id,
            'date' => $date->toDateString(),
            'start_datetime' => $startDatetime,
            'end_datetime' => $endDatetime,
            'crosses_midnight' => $day->crosses_midnight,
            'source' => 'template',
        ]);

        $shift->assignments()->create([
            'company_id' => $employee->company_id,
            'employee_id' => $employee->id,
            'status' => 'assigned',
        ]);

        if ($day->break_start_time && $day->break_end_time) {
            [$breakStart, $breakEnd] = $this->resolveBreakDatetimes($date, $day);

            $shift->breaks()->create([
                'company_id' => $employee->company_id,
                'planned_start' => $breakStart,
                'planned_end' => $breakEnd,
                'paid' => false,
            ]);
        }

        return $shift;
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function resolveDatetimes(Carbon $date, WorkScheduleDay $day): array
    {
        $startDatetime = $date->copy()->setTimeFromTimeString($day->start_time);
        $endDatetime = $day->crosses_midnight
            ? $date->copy()->addDay()->setTimeFromTimeString($day->end_time)
            : $date->copy()->setTimeFromTimeString($day->end_time);

        return [$startDatetime, $endDatetime];
    }

    /**
     * Anchors the planned break window to the shift's actual date, mirroring
     * resolveDatetimes()'s crosses_midnight handling: a break time earlier
     * than start_time falls on the following calendar day, same as
     * end_time does above.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function resolveBreakDatetimes(Carbon $date, WorkScheduleDay $day): array
    {
        $breakStart = $this->anchorBreakTime($date, $day->break_start_time, $day);
        $breakEnd = $this->anchorBreakTime($date, $day->break_end_time, $day);

        return [$breakStart, $breakEnd];
    }

    private function anchorBreakTime(Carbon $date, string $time, WorkScheduleDay $day): Carbon
    {
        if (! $day->crosses_midnight || $time >= $day->start_time) {
            return $date->copy()->setTimeFromTimeString($time);
        }

        return $date->copy()->addDay()->setTimeFromTimeString($time);
    }
}
