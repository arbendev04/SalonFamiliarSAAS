<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a transition on App\Services\Overtime\OvertimeRecordService is
 * attempted on an overtime_records row that is not currently in the required
 * precondition status for that transition. Per .ai/04-DOMAIN-MODEL.md the
 * lifecycle is a strict chain (detected -> requested -> authorized/rejected
 * -> paid); a row can only ever move forward one step at a time.
 */
class InvalidOvertimeRecordStatusException extends RuntimeException
{
    public function __construct(string $recordId, string $currentStatus, string $expectedStatus)
    {
        parent::__construct(
            "El registro de hora extra {$recordId} no se puede transicionar: su estado actual es '{$currentStatus}', se esperaba '{$expectedStatus}'."
        );
    }
}
