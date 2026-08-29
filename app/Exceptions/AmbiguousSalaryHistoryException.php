<?php

namespace App\Exceptions;

use Carbon\CarbonInterface;
use RuntimeException;

/**
 * Thrown when more than one salary_history revision is in force for the
 * same contract on the same date. Mirrors AmbiguousLaborRuleVersionException:
 * this is a data integrity bug (overlapping revisions without a proper
 * close), never something to resolve by guessing which one applies. See
 * SalaryHistory::activeAt() and .ai/04-DOMAIN-MODEL.md.
 */
class AmbiguousSalaryHistoryException extends RuntimeException
{
    public function __construct(string $contractId, CarbonInterface $date)
    {
        parent::__construct(
            "Historial salarial ambiguo para el contrato {$contractId} en la fecha {$date->toDateString()}: hay más de una revisión vigente sin cierre correcto."
        );
    }
}
