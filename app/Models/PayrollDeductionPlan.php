<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Database\Factories\PayrollDeductionPlanFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A fixed-installment deduction plan (loan/garnishment) applied to an
 * employee's payroll entries period after period until `remaining` reaches
 * zero. `remaining` is only decremented by PayrollPeriodService::close(),
 * never during a recalculation. See .ai/10-PAYROLL.md.
 */
class PayrollDeductionPlan extends Model
{
    /** @use HasFactory<PayrollDeductionPlanFactory> */
    use BelongsToCompany, HasFactory, HasUuids, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'employee_id',
        'concept_id',
        'total_amount',
        'installments',
        'installment_amount',
        'remaining',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'total_amount' => 'decimal:2',
            'installment_amount' => 'decimal:2',
            'remaining' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * @return BelongsTo<PayrollConceptDefinition, $this>
     */
    public function concept(): BelongsTo
    {
        return $this->belongsTo(PayrollConceptDefinition::class, 'concept_id');
    }
}
