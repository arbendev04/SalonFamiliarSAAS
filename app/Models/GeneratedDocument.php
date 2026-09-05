<?php

namespace App\Models;

use App\Exceptions\GeneratedDocumentImmutableException;
use App\Models\Builders\GeneratedDocumentImmutableBuilder;
use App\Models\Concerns\BelongsToCompany;
use Database\Factories\GeneratedDocumentFactory;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * The immutable record of a single generated document (currently only
 * payroll receipts — .ai/14-PDF.md). Per that doc, this table is
 * INSERT-only: a regeneration (e.g. after a reopen+correct+close cycle)
 * always writes a brand new, higher-`version` row for the same
 * reference_entity, the previous version is never edited or removed.
 *
 * Immutability is enforced in two independent layers, replicating (not
 * reusing) the AttendanceEvent/TimeCalculationRun/PayrollAdjustment pattern:
 *   1. Model events (booted() below) reject per-instance update()/delete().
 *   2. The #[UseEloquentBuilder] attribute swaps in
 *      GeneratedDocumentImmutableBuilder (a declarative alternative to
 *      overriding newEloquentBuilder() that lets Larastan resolve
 *      X::query() to GeneratedDocumentImmutableBuilder<X> without a
 *      generic-covariance mismatch — see Model::resolveCustomBuilderClass()),
 *      which rejects mass update()/delete() issued directly through the
 *      query builder — those never fire model events and would otherwise
 *      bypass layer 1.
 */
#[UseEloquentBuilder(GeneratedDocumentImmutableBuilder::class)]
class GeneratedDocument extends Model
{
    /** @use HasFactory<GeneratedDocumentFactory> */
    use BelongsToCompany, HasFactory, HasUuids;

    /**
     * The migration has no created_at column, only generated_at. Overriding
     * this constant lets ::create() populate it automatically, the same way
     * Eloquent would otherwise auto-populate created_at.
     */
    const CREATED_AT = 'generated_at';

    /**
     * There is no updated_at column at all (see the migration): a row is
     * never touched again after it is created.
     */
    const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'type',
        'reference_entity_type',
        'reference_entity_id',
        'storage_ref',
        'generated_by',
        'version',
    ];

    protected static function booted(): void
    {
        static::updating(function () {
            throw new GeneratedDocumentImmutableException;
        });

        static::deleting(function () {
            throw new GeneratedDocumentImmutableException;
        });
    }

    /**
     * Resolves to the entity this document was generated for (currently
     * always a PayrollEntry). The `reference_entity` morph name and its
     * `reference_entity_type`/`reference_entity_id` columns already follow
     * Laravel's default morphTo() naming convention (method name snake_cased
     * plus `_type`/`_id`), so no explicit column names are needed here. The
     * string stored in `reference_entity_type` resolves through the morph
     * map registered in AppServiceProvider::boot().
     *
     * @return MorphTo<Model, $this>
     */
    public function referenceEntity(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }
}
