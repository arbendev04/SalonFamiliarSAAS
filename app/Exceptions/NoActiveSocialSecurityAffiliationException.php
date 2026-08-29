<?php

namespace App\Exceptions;

use Carbon\CarbonInterface;
use RuntimeException;

/**
 * Thrown when a single active SocialSecurityAffiliation of a given
 * `entity_type` cannot be resolved for an employee on a given date. Covers
 * both failure modes with one exception, same criterion as
 * AmbiguousContractException: zero affiliations of that type in force, or
 * more than one (an overlap/gap between sub-ranges) — both are the same
 * underlying symptom, no single resolvable affiliation for that date, so
 * the message is written generically enough to fit either case.
 */
class NoActiveSocialSecurityAffiliationException extends RuntimeException
{
    public function __construct(string $employeeId, string $entityType, CarbonInterface $date)
    {
        parent::__construct(
            "No se pudo resolver una única afiliación de seguridad social vigente de tipo {$entityType} para el empleado {$employeeId} en la fecha {$date->toDateString()}."
        );
    }
}
