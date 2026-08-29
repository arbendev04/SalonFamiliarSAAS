<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Employee;
use App\Models\PayrollConceptDefinition;
use App\Models\PayrollDeductionPlan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PayrollDeductionPlan>
 */
class PayrollDeductionPlanFactory extends Factory
{
    protected $model = PayrollDeductionPlan::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $installments = fake()->numberBetween(2, 12);
        $totalAmount = fake()->randomFloat(2, 100000, 3000000);
        $installmentAmount = round($totalAmount / $installments, 2);

        return [
            'company_id' => Company::factory(),
            'employee_id' => Employee::factory(),
            'concept_id' => PayrollConceptDefinition::factory(),
            'total_amount' => $totalAmount,
            'installments' => $installments,
            'installment_amount' => $installmentAmount,
            'remaining' => $totalAmount,
        ];
    }
}
