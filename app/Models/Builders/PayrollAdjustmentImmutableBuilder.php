<?php

namespace App\Models\Builders;

use App\Exceptions\PayrollAdjustmentImmutableException;
use Illuminate\Database\Eloquent\Builder;

/**
 * Query-builder-level enforcement of immutability, used by
 * PayrollAdjustment. Model events (static::updating()/deleting()) only
 * fire for per-instance mutations; a mass update/delete through the query
 * builder (e.g. PayrollAdjustment::where(...)->update(...)) never touches
 * an Eloquent model instance and would silently bypass those listeners.
 * Overriding update()/delete() here closes that gap.
 *
 * Deliberately not a reuse of ImmutableBuilder: that class hardcodes
 * AttendanceEventImmutableException in its method bodies, so it is not
 * generic. This is a small, self-contained duplicate of the same pattern
 * for PayrollAdjustment, keeping the blast radius of this table's rules
 * away from the already-shipped AttendanceEvent/TimeCalculationRun code —
 * same duplication criterion already established in Fase 7.
 *
 * @template TModel of \Illuminate\Database\Eloquent\Model
 *
 * @extends Builder<TModel>
 */
class PayrollAdjustmentImmutableBuilder extends Builder
{
    /**
     * @param  array<string, mixed>  $values
     *
     * @throws PayrollAdjustmentImmutableException
     */
    public function update(array $values): int
    {
        throw new PayrollAdjustmentImmutableException;
    }

    /**
     * @throws PayrollAdjustmentImmutableException
     */
    public function delete(): mixed
    {
        throw new PayrollAdjustmentImmutableException;
    }
}
