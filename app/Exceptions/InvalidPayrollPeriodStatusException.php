<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a transition on App\Services\Payroll\PayrollPeriodService is
 * attempted on a payroll_periods row that is not currently in the required
 * precondition status for that transition. Mirrors
 * InvalidOvertimeRecordStatusException's exact 3-arg shape. Unlike
 * OvertimeRecord's strict single-precondition chain, several
 * PayrollPeriod transitions accept more than one valid current status (e.g.
 * close(): calculated|approved|reopened -> closed per .ai/10-PAYROLL.md) —
 * $expectedStatus is expected to carry a '|'-joined list in those cases.
 */
class InvalidPayrollPeriodStatusException extends RuntimeException
{
    public function __construct(string $periodId, string $currentStatus, string $expectedStatus)
    {
        parent::__construct(
            "El periodo de nómina {$periodId} no se puede transicionar: su estado actual es '{$currentStatus}', se esperaba '{$expectedStatus}'."
        );
    }
}
