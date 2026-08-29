<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown by App\Services\Payroll\PayrollCalculationService when an employee
 * has no attendance_records nor novelty_records covering a payroll_period at
 * all. Per the plan's product decision (composed-knitting-dusk.md, decisión
 * #2) and rule #16 of AGENTS.md, this specific employee's calculation is
 * blocked with an explicit error rather than assuming/filling a zero — the
 * rest of the batch is unaffected.
 */
class NoAttendanceOrNoveltyDataException extends RuntimeException
{
    public function __construct(string $employeeId, string $payrollPeriodId)
    {
        parent::__construct(
            "El empleado {$employeeId} no tiene ningún attendance_record ni novelty_record que cubra el periodo de nómina {$payrollPeriodId}: el cálculo de este empleado queda bloqueado, no se asume ni se rellena en cero."
        );
    }
}
