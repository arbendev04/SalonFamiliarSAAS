<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Database\Factories\PayrollPeriodFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A payroll settlement period (e.g. a biweekly cut) for a company. Its
 * lifecycle (open/calculated/approved/closed/reopened) is guarded by
 * App\Services\Payroll\PayrollPeriodService — once closed it is immutable
 * to the application, except through the explicit reopen path (ADR-026).
 * See .ai/10-PAYROLL.md.
 *
 * @property Carbon $start_date
 * @property Carbon $end_date
 * @property Carbon|null $closed_at
 */
class PayrollPeriod extends Model
{
    /** @use HasFactory<PayrollPeriodFactory> */
    use BelongsToCompany, HasFactory, HasUuids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'period_type',
        'start_date',
        'end_date',
        'status',
        'closed_by',
        'closed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'start_date' => 'date:Y-m-d',
            'end_date' => 'date:Y-m-d',
            'closed_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<PayrollEntry, $this>
     */
    public function entries(): HasMany
    {
        return $this->hasMany(PayrollEntry::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }
}
