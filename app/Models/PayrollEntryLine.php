<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Database\Factories\PayrollEntryLineFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single earning/deduction line within a PayrollEntry. Per
 * .ai/10-PAYROLL.md, this is an AJUSTE table — like AttendanceAdjustment,
 * it has no ORM-level immutability guard. It stays freely recalculable
 * (the parent PayrollEntry replaces its whole set of lines on
 * recalculation) while the parent payroll_period is not 'closed'; that
 * mutability boundary is enforced at the service layer
 * (App\Services\Payroll\PayrollCalculationService), never by the model
 * itself. Only payroll_adjustments gets the two-layer ORM guard.
 */
class PayrollEntryLine extends Model
{
    /** @use HasFactory<PayrollEntryLineFactory> */
    use BelongsToCompany, HasFactory, HasUuids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'payroll_entry_id',
        'concept_id',
        'contract_id',
        'type',
        'quantity',
        'rate',
        'amount',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'rate' => 'decimal:4',
            'amount' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<PayrollEntry, $this>
     */
    public function payrollEntry(): BelongsTo
    {
        return $this->belongsTo(PayrollEntry::class);
    }

    /**
     * @return BelongsTo<PayrollConceptDefinition, $this>
     */
    public function concept(): BelongsTo
    {
        return $this->belongsTo(PayrollConceptDefinition::class, 'concept_id');
    }

    /**
     * @return BelongsTo<EmploymentContract, $this>
     */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(EmploymentContract::class, 'contract_id');
    }
}
