<?php

namespace App\Models\Builders;

use App\Exceptions\GeneratedDocumentImmutableException;
use Illuminate\Database\Eloquent\Builder;

/**
 * Query-builder-level enforcement of immutability, used by
 * GeneratedDocument. Model events (static::updating()/deleting()) only fire
 * for per-instance mutations; a mass update/delete through the query builder
 * (e.g. GeneratedDocument::where(...)->update(...)) never touches an
 * Eloquent model instance and would silently bypass those listeners.
 * Overriding update()/delete() here closes that gap.
 *
 * Deliberately not a reuse of PayrollAdjustmentImmutableBuilder or
 * ImmutableBuilder: each hardcodes its own exception in its method bodies,
 * so none is generic. This is a small, self-contained duplicate of the same
 * pattern for GeneratedDocument, keeping the blast radius of this table's
 * rules away from the already-shipped AttendanceEvent/TimeCalculationRun/
 * PayrollAdjustment code — same duplication criterion already established in
 * Fase 7/9.
 *
 * @template TModel of \Illuminate\Database\Eloquent\Model
 *
 * @extends Builder<TModel>
 */
class GeneratedDocumentImmutableBuilder extends Builder
{
    /**
     * @param  array<string, mixed>  $values
     *
     * @throws GeneratedDocumentImmutableException
     */
    public function update(array $values): int
    {
        throw new GeneratedDocumentImmutableException;
    }

    /**
     * @throws GeneratedDocumentImmutableException
     */
    public function delete(): mixed
    {
        throw new GeneratedDocumentImmutableException;
    }
}
