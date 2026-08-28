<?php

namespace App\Exceptions;

use Carbon\CarbonInterface;
use RuntimeException;

/**
 * Thrown when the Time Calculation Engine finds attendance events for an
 * employee/date but is missing a critical one (a clock_in without a
 * matching clock_out, or vice versa) needed to compute worked time. Per
 * .ai/09-TIME-CALCULATION.md, "Errores", the calculation for that date is
 * explicitly blocked; the engine never assumes or interpolates a missing
 * value.
 */
class MissingCriticalAttendanceEventException extends RuntimeException
{
    public function __construct(string $employeeId, CarbonInterface $date, string $missingEventType)
    {
        parent::__construct(
            "No se puede calcular la asistencia del empleado {$employeeId} para la fecha {$date->toDateString()}: falta el evento crítico '{$missingEventType}'."
        );
    }
}
