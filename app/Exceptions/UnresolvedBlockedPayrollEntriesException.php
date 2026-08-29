<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown by App\Services\Payroll\PayrollPeriodService::close() when the
 * period still has one or more payroll_entries in status='blocked'. Per
 * .ai/10-PAYROLL.md, a period cannot close while any employee's calculation
 * remains unresolved — those entries must be fixed (e.g. an ambiguous
 * contract corrected, missing attendance data supplied) and recalculated
 * before closing can proceed.
 */
class UnresolvedBlockedPayrollEntriesException extends RuntimeException
{
    public function __construct(string $payrollPeriodId, int $blockedCount)
    {
        parent::__construct(
            "El periodo de nómina {$payrollPeriodId} no se puede cerrar: quedan {$blockedCount} empleado(s) con la entrada bloqueada."
        );
    }
}
