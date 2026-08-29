<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown whenever code attempts to UPDATE or DELETE a payroll_adjustments
 * row, whether through a model instance or the query builder. Per
 * .ai/10-PAYROLL.md (ADR-026), this table is INSERT-only: a correction over
 * a closed payroll_entry is always a new payroll_adjustments row (and, for
 * the next_period mechanism, a new payroll_entry_lines row in the target
 * period), never an edit of an existing one.
 */
class PayrollAdjustmentImmutableException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(
            'payroll_adjustments es inmutable: no se permite UPDATE ni DELETE. Una corrección posterior siempre crea una fila nueva.'
        );
    }
}
