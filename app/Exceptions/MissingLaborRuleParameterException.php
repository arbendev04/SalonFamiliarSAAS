<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a `labor_rule_version` is found and unambiguous, but its
 * `parameters` JSON is missing a required key (e.g. `tolerance_minutes` or
 * `rounding_minutes`) that the Time Calculation Engine needs. Per
 * .ai/AGENTS.md rule 15, no legal/business parameter is ever assumed by
 * default; a missing key is a hard, explicit, blocking error that must be
 * configured before the calculation can proceed.
 */
class MissingLaborRuleParameterException extends RuntimeException
{
    public function __construct(string $ruleVersionId, string $missingParameterKey)
    {
        parent::__construct(
            "La versión de regla laboral {$ruleVersionId} no tiene configurado el parámetro requerido '{$missingParameterKey}': no se asume ningún valor por defecto."
        );
    }
}
