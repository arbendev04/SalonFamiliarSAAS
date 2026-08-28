<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Validator;

class StoreWorkScheduleTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('schedules.write');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'days' => ['required', 'array', 'min:1'],
            'days.*.day_of_week' => ['required', 'integer', 'between:0,6'],
            'days.*.start_time' => ['required', 'date_format:H:i'],
            'days.*.end_time' => ['required', 'date_format:H:i'],
            'days.*.crosses_midnight' => ['sometimes', 'boolean'],
            'days.*.break_start_time' => ['sometimes', 'nullable', 'date_format:H:i'],
            'days.*.break_end_time' => ['sometimes', 'nullable', 'date_format:H:i'],
        ];
    }

    /**
     * Reject a planned break that only defines one of its two boundaries, or
     * that falls outside the day's start_time/end_time window. Mirrors
     * ShiftGenerator::resolveDatetimes()'s crosses_midnight anchoring: a
     * break time earlier than start_time is treated as falling on the
     * following calendar day, exactly like end_time is on a crosses_midnight
     * day (see .ai/08-SHIFTS.md).
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $days = $this->input('days');

            if (! is_array($days)) {
                return;
            }

            foreach ($days as $index => $day) {
                if (! is_array($day)) {
                    continue;
                }

                $this->validateBreakWindow($validator, $index, $day);
            }
        });
    }

    /**
     * @param  array<string, mixed>  $day
     */
    private function validateBreakWindow(Validator $validator, int|string $index, array $day): void
    {
        $breakStart = $day['break_start_time'] ?? null;
        $breakEnd = $day['break_end_time'] ?? null;

        if (($breakStart === null) !== ($breakEnd === null)) {
            $validator->errors()->add(
                "days.{$index}.break_start_time",
                'El descanso planificado requiere hora de inicio y fin, o ninguna de las dos.',
            );

            return;
        }

        if ($breakStart === null || $breakEnd === null) {
            return;
        }

        $startTime = $day['start_time'] ?? null;
        $endTime = $day['end_time'] ?? null;

        if (! is_string($startTime) || ! is_string($endTime) || ! is_string($breakStart) || ! is_string($breakEnd)) {
            return;
        }

        $crossesMidnight = filter_var($day['crosses_midnight'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if (! $this->breakWindowIsInsideShift($startTime, $endTime, $breakStart, $breakEnd, $crossesMidnight)) {
            $validator->errors()->add(
                "days.{$index}.break_start_time",
                'El descanso planificado debe estar dentro del horario del día.',
            );
        }
    }

    private function breakWindowIsInsideShift(
        string $startTime,
        string $endTime,
        string $breakStart,
        string $breakEnd,
        bool $crossesMidnight,
    ): bool {
        $anchor = Carbon::today();

        $shiftStart = $anchor->copy()->setTimeFromTimeString($startTime);
        $shiftEnd = $crossesMidnight
            ? $anchor->copy()->addDay()->setTimeFromTimeString($endTime)
            : $anchor->copy()->setTimeFromTimeString($endTime);

        $breakStartDatetime = $this->anchorBreakTime($anchor, $breakStart, $startTime, $crossesMidnight);
        $breakEndDatetime = $this->anchorBreakTime($anchor, $breakEnd, $startTime, $crossesMidnight);

        return $breakStartDatetime->gte($shiftStart)
            && $breakEndDatetime->gt($breakStartDatetime)
            && $breakEndDatetime->lte($shiftEnd);
    }

    private function anchorBreakTime(Carbon $anchor, string $time, string $shiftStartTime, bool $crossesMidnight): Carbon
    {
        if (! $crossesMidnight || $time >= $shiftStartTime) {
            return $anchor->copy()->setTimeFromTimeString($time);
        }

        return $anchor->copy()->addDay()->setTimeFromTimeString($time);
    }
}
