<?php

namespace App\Exceptions;

use Carbon\CarbonInterface;
use RuntimeException;

/**
 * Thrown when no labor_rule_version is in force for the required rule_type,
 * company, and date — either because no labor_rules row exists at all for
 * that company+rule_type, or because one exists but LaborRuleVersion::
 * activeFor() found no version covering the date. Per
 * .ai/09-TIME-CALCULATION.md ("Errores") and .ai/AGENTS.md rule 15, this is
 * an explicit blocking error: the engine never assumes a default labor rule
 * version when none is configured for the date.
 */
class NoActiveLaborRuleVersionException extends RuntimeException
{
    public function __construct(string $ruleType, ?string $companyId, CarbonInterface $date)
    {
        $companyDescription = $companyId ?? 'sin empresa (default de plataforma)';

        parent::__construct(
            "No hay una versión de regla laboral vigente de tipo '{$ruleType}' para la empresa {$companyDescription} en la fecha {$date->toDateString()}: no se asume ningún valor por defecto."
        );
    }
}
