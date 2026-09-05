<?php

namespace App\Models;

use App\Exceptions\AttendanceEventImmutableException;
use App\Models\Builders\ImmutableBuilder;
use App\Models\Concerns\BelongsToCompany;
use Database\Factories\AttendanceEventFactory;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The immutable record of a single attendance marking. Per
 * .ai/07-ATTENDANCE.md (ADR-003), this table is INSERT-only: no role, not
 * even SUPER_ADMIN, may UPDATE or DELETE a row. Corrections happen through
 * a separate attendance_adjustments mechanism (future phase) that always
 * preserves the original event.
 *
 * Immutability is enforced in two independent layers:
 *   1. Model events (booted() below) reject per-instance update()/delete().
 *   2. The #[UseEloquentBuilder] attribute swaps in ImmutableBuilder (a
 *      declarative alternative to overriding newEloquentBuilder() that lets
 *      Larastan resolve X::query() to ImmutableBuilder<X> without a
 *      generic-covariance mismatch — see Model::resolveCustomBuilderClass()),
 *      which rejects mass update()/delete() issued directly through the
 *      query builder — those never fire model events and would otherwise
 *      bypass layer 1.
 *
 * @property Carbon $event_datetime
 * @property array<string, mixed> $metadata
 */
#[UseEloquentBuilder(ImmutableBuilder::class)]
class AttendanceEvent extends Model
{
    /** @use HasFactory<AttendanceEventFactory> */
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
        'event_type',
        'event_datetime',
        'source',
        'device_id',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event_datetime' => 'datetime',
            'metadata' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new AttendanceEventImmutableException;
        });

        static::deleting(function () {
            throw new AttendanceEventImmutableException;
        });
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * @return BelongsTo<AttendanceDevice, $this>
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(AttendanceDevice::class, 'device_id');
    }
}
