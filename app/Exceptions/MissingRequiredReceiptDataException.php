<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a payroll receipt cannot be generated for a PayrollEntry
 * because data it strictly requires is missing (e.g. no payroll_entry_lines
 * at all). Per .ai/AGENTS.md rule 15, no legal/business document is ever
 * generated from incomplete or assumed data — a missing prerequisite is a
 * hard, explicit, blocking error rather than a silently empty receipt.
 */
class MissingRequiredReceiptDataException extends RuntimeException
{
    public function __construct(string $payrollEntryId, string $reason)
    {
        parent::__construct(
            "No se puede generar el comprobante de nómina para el payroll_entry {$payrollEntryId}: {$reason}"
        );
    }
}
