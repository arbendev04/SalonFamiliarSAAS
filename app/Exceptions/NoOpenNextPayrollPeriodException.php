<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown by App\Services\Payroll\PayrollAdjustmentService::adjustInNextPeriod()
 * when no payroll_periods row in status open/calculated exists after the
 * employee's closed entry to carry the correction. Per .ai/10-PAYROLL.md
 * (ADR-026) and rule #16 of AGENTS.md, a suitable period is never created
 * automatically — that would be inventing a scheduling policy on the
 * caller's behalf. One must be created first through the normal period
 * creation flow.
 */
class NoOpenNextPayrollPeriodException extends RuntimeException
{
    public function __construct(string $employeeId, string $currentPeriodId)
    {
        parent::__construct(
            "No existe un periodo de nómina abierto o calculado posterior a {$currentPeriodId} para el empleado {$employeeId}: cree uno antes de registrar la corrección, no se crea automáticamente."
        );
    }
}
