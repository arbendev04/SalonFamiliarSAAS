<?php

namespace App\Models;

use App\Exceptions\TimeCalculationRunImmutableException;
use App\Models\Builders\TimeCalculationRunImmutableBuilder;
use App\Models\Concerns\BelongsToCompany;
use Database\Factories\TimeCalculationRunFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The immutable audit trace of a single time-calculation run. Per
 * .ai/09-TIME-CALCULATION.md, a row is only ever written on a successful
 * calculation, together with (never independently of) the
 * attendance_records row it points to via output_ref — no role may UPDATE
 * or DELETE a row afterward. A recalculation writes a brand new row.
 *
 * Immutability is enforced in two independent layers, replicating (not
 * reusing) the AttendanceEvent pattern:
 *   1. Model events (booted() below) reject per-instance update()/delete().
 *   2. newEloquentBuilder() swaps in TimeCalculationRunImmutableBuilder,
 *      which rejects mass update()/delete() issued directly through the
 *      query builder — those never fire model events and would otherwise
 *      bypass layer 1.
 */
class TimeCalculationRun extends Model
{
    /** @use HasFactory<TimeCalculationRunFactory> */
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
        'employee_id',
        'date',
        'rule_version_id',
        'inputs_hash',
        'output_ref',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new TimeCalculationRunImmutableException;
        });

        static::deleting(function () {
            throw new TimeCalculationRunImmutableException;
        });
    }

    /**
     * @return TimeCalculationRunImmutableBuilder<$this>
     */
    public function newEloquentBuilder($query): TimeCalculationRunImmutableBuilder
    {
        return new TimeCalculationRunImmutableBuilder($query);
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * @return BelongsTo<LaborRuleVersion, $this>
     */
    public function ruleVersion(): BelongsTo
    {
        return $this->belongsTo(LaborRuleVersion::class, 'rule_version_id');
    }

    /**
     * @return BelongsTo<AttendanceRecord, $this>
     */
    public function attendanceRecord(): BelongsTo
    {
        return $this->belongsTo(AttendanceRecord::class, 'output_ref');
    }
}
