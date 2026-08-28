<?php

namespace App\Services\TimeCalculation;

use App\Models\AttendanceAdjustment;
use App\Models\AttendanceEvent;
use App\Models\Employee;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Reconstructs "what attendance events actually count" for an employee
 * within a time window, per .ai/07-ATTENDANCE.md (Flujo 2) and the
 * "Resolver de eventos netos" section of the Fase 7 plan. This is the sole
 * input the TimeCalculationEngine (built in a later commit) needs from
 * Attendance: raw attendance_events reconciled against approved
 * attendance_adjustments.
 *
 * `add`-type adjustments need NO special handling here. Once an `add`
 * adjustment is approved — whether auto-approved on create, or approved
 * later via the pending flow — AttendanceAdjustmentService::
 * insertEventForAddAdjustment() already inserts a REAL row into
 * attendance_events. By the time this resolver runs, that row is already
 * present in the raw event query below and flows through untouched, like
 * any other event.
 *
 * `modify`/`invalidate` adjustments, in contrast, never touch
 * attendance_events (the original row is immutable, ADR-003) — they are
 * only resolved here, at read time, against the raw event they target.
 * When two APPROVED adjustments end up targeting the same
 * original_event_id — attendance_adjustments has no "supersedes" chain, so
 * nothing prevents a second correction over an already-corrected event —
 * the PENDING DECISION documented in .ai/07-ATTENDANCE.md (Flujo 2, step 4)
 * resolves it as: the most recent adjustment by created_at wins entirely.
 * The two are never merged field by field, even when they touch different
 * keys of corrected_value.
 */
class AttendanceNetEventsResolver
{
    /**
     * @return Collection<int, array{event_id: string, event_type: string, event_datetime: Carbon}>
     */
    public function resolve(Employee $employee, Carbon $windowStart, Carbon $windowEnd): Collection
    {
        $rawEvents = AttendanceEvent::query()
            ->where('employee_id', $employee->id)
            ->whereBetween('event_datetime', [$windowStart, $windowEnd])
            ->orderBy('event_datetime')
            ->get();

        $latestApprovedAdjustmentByEventId = AttendanceAdjustment::query()
            ->where('employee_id', $employee->id)
            ->where('status', 'approved')
            ->whereIn('type', ['modify', 'invalidate'])
            ->whereIn('original_event_id', $rawEvents->pluck('id'))
            ->get()
            ->groupBy('original_event_id')
            ->map(fn (Collection $adjustments): AttendanceAdjustment => $adjustments->sortByDesc('created_at')->first());

        return collect($rawEvents)
            ->map(function (AttendanceEvent $event) use ($latestApprovedAdjustmentByEventId): ?array {
                $adjustment = $latestApprovedAdjustmentByEventId->get($event->id);

                if ($adjustment === null) {
                    return [
                        'event_id' => $event->id,
                        'event_type' => $event->event_type,
                        'event_datetime' => $event->event_datetime,
                    ];
                }

                if ($adjustment->type === 'invalidate') {
                    return null;
                }

                return $this->applyModify($event, $adjustment);
            })
            ->filter()
            ->sortBy('event_datetime')
            ->values();
    }

    /**
     * @return array{event_id: string, event_type: string, event_datetime: Carbon}
     */
    private function applyModify(AttendanceEvent $event, AttendanceAdjustment $adjustment): array
    {
        $correctedValue = $adjustment->corrected_value;

        return [
            'event_id' => $event->id,
            'event_type' => $correctedValue['event_type'] ?? $event->event_type,
            'event_datetime' => array_key_exists('event_datetime', $correctedValue)
                ? Carbon::parse($correctedValue['event_datetime'])
                : $event->event_datetime,
        ];
    }
}
