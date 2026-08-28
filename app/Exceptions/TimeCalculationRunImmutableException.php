<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown whenever code attempts to UPDATE or DELETE a time_calculation_runs
 * row, whether through a model instance or the query builder. Per
 * .ai/09-TIME-CALCULATION.md, this table is INSERT-only: it is the
 * immutable audit trace of a calculation run, always written together with
 * (never separately from) the attendance_records row it produced. A
 * recalculation writes a brand new row instead of touching an old one.
 */
class TimeCalculationRunImmutableException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(
            'time_calculation_runs es inmutable: no se permite UPDATE ni DELETE. Un recálculo escribe una fila nueva.'
        );
    }
}
