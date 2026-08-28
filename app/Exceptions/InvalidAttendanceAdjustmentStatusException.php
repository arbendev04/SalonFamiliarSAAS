<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when approve()/reject() is attempted on an attendance_adjustment
 * that is not currently `pending`. Per .ai/07-ATTENDANCE.md an adjustment is
 * approved or rejected exactly once; a later correction is a brand-new
 * adjustment row, never a re-edit of an already-decided one.
 */
class InvalidAttendanceAdjustmentStatusException extends RuntimeException
{
    public function __construct(string $adjustmentId, string $currentStatus)
    {
        parent::__construct(
            "El ajuste de asistencia {$adjustmentId} no se puede aprobar/rechazar: su estado actual es '{$currentStatus}', se esperaba 'pending'."
        );
    }
}
