<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown whenever code attempts to UPDATE or DELETE an attendance_events
 * row, whether through a model instance or the query builder. Per
 * .ai/07-ATTENDANCE.md and ADR-003, attendance_events is INSERT-only: what
 * really happened is never edited or removed, without exception for any
 * role. Corrections go through attendance_adjustments instead.
 */
class AttendanceEventImmutableException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(
            'attendance_events es inmutable: no se permite UPDATE ni DELETE. Use attendance_adjustments para corregir un evento.'
        );
    }
}
