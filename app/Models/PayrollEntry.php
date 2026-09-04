<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Database\Factories\PayrollEntryFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * A single employee's settlement within a payroll_period. Per
 * .ai/10-PAYROLL.md, this is an AJUSTE table — like AttendanceAdjustment,
 * it has no ORM-level immutability guard. It (and its payroll_entry_lines)
 * stay freely recalculable while the parent payroll_period is not
 * 'closed'; that mutability boundary is enforced at the service layer
 * (App\Services\Payroll\PayrollCalculationService / PayrollPeriodService),
 * never by the model itself. Only payroll_adjustments (the append-only
 * correction trail for a closed entry) gets the two-layer ORM guard.
 */
class PayrollEntry extends Model
{
    /** @use HasFactory<PayrollEntryFactory> */
    use BelongsToCompany, HasFactory, HasUuids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'payroll_period_id',
        'employee_id',
        'contract_id',
        'status',
        'gross_total',
        'deductions_total',
        'net_total',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'gross_total' => 'decimal:2',
            'deductions_total' => 'decimal:2',
            'net_total' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<PayrollPeriod, $this>
     */
    public function payrollPeriod(): BelongsTo
    {
        return $this->belongsTo(PayrollPeriod::class);
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * @return BelongsTo<EmploymentContract, $this>
     */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(EmploymentContract::class, 'contract_id');
    }

    /**
     * @return HasMany<PayrollEntryLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(PayrollEntryLine::class);
    }

    /**
     * @return HasMany<SocialSecurityContribution, $this>
     */
    public function socialSecurityContributions(): HasMany
    {
        return $this->hasMany(SocialSecurityContribution::class);
    }

    /**
     * @return HasMany<PayrollAdjustment, $this>
     */
    public function payrollAdjustments(): HasMany
    {
        return $this->hasMany(PayrollAdjustment::class);
    }

    /**
     * @return MorphMany<GeneratedDocument, $this>
     */
    public function generatedDocuments(): MorphMany
    {
        return $this->morphMany(GeneratedDocument::class, 'reference_entity');
    }
}
