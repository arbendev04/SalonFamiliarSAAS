<?php

namespace App\Models;

use App\Exceptions\PayrollAdjustmentImmutableException;
use App\Models\Builders\PayrollAdjustmentImmutableBuilder;
use App\Models\Concerns\BelongsToCompany;
use Database\Factories\PayrollAdjustmentFactory;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The append-only correction trail over a closed PayrollEntry (ADR-026).
 * Per .ai/10-PAYROLL.md, a row is written once (either mechanism —
 * next_period or reopen) and never edited or removed afterward; the actual
 * monetary correction lands as a new payroll_entry_lines row in whichever
 * period the mechanism resolves to, the original closed entry is never
 * touched.
 *
 * Immutability is enforced in two independent layers, replicating (not
 * reusing) the AttendanceEvent/TimeCalculationRun pattern:
 *   1. Model events (booted() below) reject per-instance update()/delete().
 *   2. The #[UseEloquentBuilder] attribute swaps in
 *      PayrollAdjustmentImmutableBuilder (a declarative alternative to
 *      overriding newEloquentBuilder() that lets Larastan resolve
 *      X::query() to PayrollAdjustmentImmutableBuilder<X> without a
 *      generic-covariance mismatch — see Model::resolveCustomBuilderClass()),
 *      which rejects mass update()/delete() issued directly through the
 *      query builder — those never fire model events and would otherwise
 *      bypass layer 1.
 *
 * @property array<string, mixed> $original_value
 * @property array<string, mixed> $corrected_value
 */
#[UseEloquentBuilder(PayrollAdjustmentImmutableBuilder::class)]
class PayrollAdjustment extends Model
{
    /** @use HasFactory<PayrollAdjustmentFactory> */
    use BelongsToCompany, HasFactory, HasUuids;

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
        'payroll_entry_id',
        'mechanism',
        'original_value',
        'corrected_value',
        'reason',
        'created_by',
        'applied_in_period_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'original_value' => 'array',
            'corrected_value' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new PayrollAdjustmentImmutableException;
        });

        static::deleting(function () {
            throw new PayrollAdjustmentImmutableException;
        });
    }

    /**
     * @return BelongsTo<PayrollEntry, $this>
     */
    public function payrollEntry(): BelongsTo
    {
        return $this->belongsTo(PayrollEntry::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<PayrollPeriod, $this>
     */
    public function appliedInPeriod(): BelongsTo
    {
        return $this->belongsTo(PayrollPeriod::class, 'applied_in_period_id');
    }
}
