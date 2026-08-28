<?php

namespace App\Models\Builders;

use App\Exceptions\AttendanceEventImmutableException;
use Illuminate\Database\Eloquent\Builder;

/**
 * Query-builder-level enforcement of immutability, used by AttendanceEvent.
 * Model events (static::updating()/deleting()) only fire for per-instance
 * mutations; a mass update/delete through the query builder (e.g.
 * AttendanceEvent::where(...)->update(...)) never touches an Eloquent
 * model instance and would silently bypass those listeners. Overriding
 * update()/delete() here closes that gap.
 *
 * @template TModel of \Illuminate\Database\Eloquent\Model
 *
 * @extends Builder<TModel>
 */
class ImmutableBuilder extends Builder
{
    /**
     * @param  array<string, mixed>  $values
     *
     * @throws AttendanceEventImmutableException
     */
    public function update(array $values): int
    {
        throw new AttendanceEventImmutableException;
    }

    /**
     * @throws AttendanceEventImmutableException
     */
    public function delete(): mixed
    {
        throw new AttendanceEventImmutableException;
    }
}
