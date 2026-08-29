<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when approve()/reject() is attempted on a leave_records row that is
 * not currently `pending`. Per .ai/04-DOMAIN-MODEL.md a leave record is
 * approved or rejected exactly once; a later correction is a brand-new
 * leave_records row, never a re-edit of an already-decided one.
 */
class InvalidLeaveRecordStatusException extends RuntimeException
{
    public function __construct(string $recordId, string $currentStatus)
    {
        parent::__construct(
            "El registro de ausencia {$recordId} no se puede aprobar/rechazar: su estado actual es '{$currentStatus}', se esperaba 'pending'."
        );
    }
}
