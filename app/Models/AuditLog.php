<?php

namespace App\Models;

use App\Exceptions\AuditLogImmutableException;
use App\Models\Builders\AuditLogImmutableBuilder;
use App\Models\Concerns\BelongsToCompany;
use Database\Factories\AuditLogFactory;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable trail of sensitive business actions (.ai/16-AUDIT.md). Rows are
 * only ever inserted through App\Services\Audit\AuditLogger — never updated
 * or deleted by any code path, not even for SUPER_ADMIN.
 *
 * Immutability is enforced in two independent layers, replicating (not
 * reusing) the AttendanceEvent/TimeCalculationRun/PayrollAdjustment/
 * GeneratedDocument pattern:
 *   1. Model events (booted() below) reject per-instance update()/delete().
 *   2. The #[UseEloquentBuilder] attribute swaps in AuditLogImmutableBuilder
 *      (a declarative alternative to overriding newEloquentBuilder() that
 *      lets Larastan resolve X::query() to AuditLogImmutableBuilder<X>
 *      without a generic-covariance mismatch — see
 *      Model::resolveCustomBuilderClass()), which rejects mass
 *      update()/delete() issued directly through the query builder —
 *      those never fire model events and would otherwise bypass layer 1.
 *
 * @property array<string, mixed>|null $old_value
 * @property array<string, mixed>|null $new_value
 */
#[UseEloquentBuilder(AuditLogImmutableBuilder::class)]
class AuditLog extends Model
{
    /** @use HasFactory<AuditLogFactory> */
    use BelongsToCompany, HasFactory, HasUuids;

    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'user_id',
        'action',
        'entity_type',
        'entity_id',
        'old_value',
        'new_value',
        'reason',
        'ip_address',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'old_value' => 'array',
            'new_value' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new AuditLogImmutableException;
        });

        static::deleting(function () {
            throw new AuditLogImmutableException;
        });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
