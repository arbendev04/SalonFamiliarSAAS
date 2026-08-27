<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when assigning an employee to a shift would overlap another shift
 * they are already (non-cancelled) assigned to. See .ai/08-SHIFTS.md,
 * "Errores": this is rejected explicitly and never allowed silently.
 */
class OverlappingShiftAssignmentException extends RuntimeException
{
    public function __construct(string $employeeId)
    {
        parent::__construct(
            "El empleado {$employeeId} ya tiene un turno asignado que se solapa con este horario."
        );
    }
}
