<?php

namespace App\Exceptions;

use Carbon\CarbonInterface;
use RuntimeException;

/**
 * Thrown when more than one labor rule version is in force for the same
 * labor rule on the same date. This is a data integrity bug (overlapping
 * versions without a proper close), never something to resolve by guessing
 * which one applies. See .ai/05-DATABASE.md.
 */
class AmbiguousLaborRuleVersionException extends RuntimeException
{
    public function __construct(string $laborRuleId, CarbonInterface $date)
    {
        parent::__construct(
            "Regla laboral ambigua para la regla {$laborRuleId} en la fecha {$date->toDateString()}: hay más de una versión vigente sin cierre correcto."
        );
    }
}
