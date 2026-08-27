<?php

namespace App\Exceptions;

use Carbon\CarbonInterface;
use RuntimeException;

/**
 * Thrown when more than one employment contract is in force for the same
 * employee on the same date. This is a data integrity bug (overlapping
 * contracts without a proper close), never something to resolve by
 * guessing which one applies. See .ai/04-DOMAIN-MODEL.md, "Errores".
 */
class AmbiguousContractException extends RuntimeException
{
    public function __construct(string $employeeId, CarbonInterface $date)
    {
        parent::__construct(
            "Contrato ambiguo para el empleado {$employeeId} en la fecha {$date->toDateString()}: hay más de un contrato vigente sin cierre correcto."
        );
    }
}
