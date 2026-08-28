<?php

namespace App\Models\Builders;

use App\Exceptions\TimeCalculationRunImmutableException;
use Illuminate\Database\Eloquent\Builder;

/**
 * Query-builder-level enforcement of immutability, used by
 * TimeCalculationRun. Model events (static::updating()/deleting()) only
 * fire for per-instance mutations; a mass update/delete through the query
 * builder (e.g. TimeCalculationRun::where(...)->update(...)) never touches
 * an Eloquent model instance and would silently bypass those listeners.
 * Overriding update()/delete() here closes that gap.
 *
 * Deliberately not a reuse of ImmutableBuilder: that class hardcodes
 * AttendanceEventImmutableException in its method bodies, so it is not
 * generic. This is a small, self-contained duplicate of the same pattern
 * for TimeCalculationRun, keeping the blast radius of this table's rules
 * away from the already-shipped AttendanceEvent code.
 *
 * @template TModel of \Illuminate\Database\Eloquent\Model
 *
 * @extends Builder<TModel>
 */
class TimeCalculationRunImmutableBuilder extends Builder
{
    /**
     * @param  array<string, mixed>  $values
     *
     * @throws TimeCalculationRunImmutableException
     */
    public function update(array $values): int
    {
        throw new TimeCalculationRunImmutableException;
    }

    /**
     * @throws TimeCalculationRunImmutableException
     */
    public function delete(): mixed
    {
        throw new TimeCalculationRunImmutableException;
    }
}
