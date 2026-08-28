<?php

namespace App\Services\Attendance;

use App\Models\AttendanceEvent;
use App\Models\Employee;
use Illuminate\Support\Carbon;

/**
 * Implements Flujo 1 of .ai/07-ATTENDANCE.md: turning a raw marking attempt
 * into an attendance_event row, applying deduplication and out-of-sequence
 * tolerance before the immutable row is written.
 *
 * No AuditLogger call happens here on purpose: .ai/07-ATTENDANCE.md only
 * requires an audit trail for the adjustment flow (Flujo 2), not for normal
 * event recording (Flujo 1). Auditing every clock-in would be noise the
 * spec never asks for.
 */
class AttendanceEventRecorder
{
    /**
     * The expected order of a normal day. An incoming event whose type does
     * not immediately follow the employee's last event of the same
     * calendar day is still recorded (never rejected) but flagged.
     *
     * @var list<string>
     */
    private const SEQUENCE = ['clock_in', 'break_start', 'break_end', 'clock_out'];

    /**
     * The deduplication window: two events of the same employee/type
     * within 1 minute of each other (in either direction) are treated as
     * the same physical marking.
     */
    private const DEDUPLICATION_WINDOW_MINUTES = 1;

    /**
     * @param  array<string, mixed>|null  $extraMetadata  Additional metadata
     *                                                    merged into the stored row on a fresh insert (e.g. the
     *                                                    `created_from_adjustment_id` link set by
     *                                                    AttendanceAdjustmentService when an `add` adjustment is
     *                                                    approved). Ignored when a duplicate is matched instead of
     *                                                    inserted, since no new row is written in that case. Optional and
     *                                                    appended last to stay backward compatible with existing callers.
     */
    public function record(
        Employee $employee,
        string $eventType,
        Carbon $eventDatetime,
        string $source,
        ?string $deviceId = null,
        ?array $extraMetadata = null,
    ): AttendanceEvent {
        $duplicate = $this->findDuplicate($employee, $eventType, $eventDatetime);

        if ($duplicate) {
            return $duplicate;
        }

        $metadata = array_merge(
            $this->anomalyMetadata($employee, $eventType, $eventDatetime) ?? [],
            $extraMetadata ?? [],
        );

        return AttendanceEvent::create([
            'company_id' => $employee->company_id,
            'employee_id' => $employee->id,
            'event_type' => $eventType,
            'event_datetime' => $eventDatetime,
            'source' => $source,
            'device_id' => $deviceId,
            'metadata' => $metadata === [] ? null : $metadata,
        ]);
    }

    /**
     * .ai/05-DATABASE.md's attendance_events schema has no `status` or
     * `duplicate_of` column — deduplication is expressed as "don't insert a
     * second row", not "insert and flag as duplicate", because the schema
     * gives no column to flag it with. Returning the existing row lets the
     * caller treat both paths (fresh insert vs. matched duplicate)
     * uniformly as "here is the event that represents this marking".
     *
     * Known limitation: this check-then-insert is not atomic. Two requests
     * for the same employee/type landing inside the same ~1 minute window
     * at nearly the same instant could both pass this check and each
     * insert a row, because there is no natural unique-index shape for a
     * sliding 60-second window (a fixed-bucket unique index would not
     * match the "within N minutes of each other" semantics). This mirrors
     * the same accepted gap already documented on
     * ShiftAssignment::overlapsForEmployee() — the spec does not require a
     * hard DB constraint here either.
     */
    private function findDuplicate(Employee $employee, string $eventType, Carbon $eventDatetime): ?AttendanceEvent
    {
        return AttendanceEvent::query()
            ->where('employee_id', $employee->id)
            ->where('event_type', $eventType)
            ->whereBetween('event_datetime', [
                $eventDatetime->clone()->subMinutes(self::DEDUPLICATION_WINDOW_MINUTES),
                $eventDatetime->clone()->addMinutes(self::DEDUPLICATION_WINDOW_MINUTES),
            ])
            ->first();
    }

    /**
     * @return array{anomaly: string}|null
     */
    private function anomalyMetadata(Employee $employee, string $eventType, Carbon $eventDatetime): ?array
    {
        $lastEventOfTheDay = AttendanceEvent::query()
            ->where('employee_id', $employee->id)
            ->whereDate('event_datetime', $eventDatetime->toDateString())
            ->where('event_datetime', '<', $eventDatetime)
            ->orderByDesc('event_datetime')
            ->first();

        $expectedIndex = $lastEventOfTheDay
            ? array_search($lastEventOfTheDay->event_type, self::SEQUENCE, true) + 1
            : 0;

        $actualIndex = array_search($eventType, self::SEQUENCE, true);

        if ($actualIndex === false || $actualIndex !== $expectedIndex) {
            return ['anomaly' => 'out_of_sequence'];
        }

        return null;
    }
}
