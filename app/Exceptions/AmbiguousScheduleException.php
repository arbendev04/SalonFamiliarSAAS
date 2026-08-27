<?php

namespace App\Exceptions;

use Carbon\CarbonInterface;
use RuntimeException;

/**
 * Thrown when more than one work schedule assignment is in force for the
 * same employee on the same date. Under normal use this cannot happen —
 * assigning a new schedule always closes the previous one first (see
 * .ai/08-SHIFTS.md) — so this only fires against inconsistent data.
 */
class AmbiguousScheduleException extends RuntimeException
{
    public function __construct(string $employeeId, CarbonInterface $date)
    {
        parent::__construct(
            "Jornada ambigua para el empleado {$employeeId} en la fecha {$date->toDateString()}: hay más de una plantilla vigente sin cierre correcto."
        );
    }
}
