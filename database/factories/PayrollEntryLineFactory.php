<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\EmploymentContract;
use App\Models\PayrollConceptDefinition;
use App\Models\PayrollEntry;
use App\Models\PayrollEntryLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PayrollEntryLine>
 */
class PayrollEntryLineFactory extends Factory
{
    protected $model = PayrollEntryLine::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'payroll_entry_id' => PayrollEntry::factory(),
            'concept_id' => PayrollConceptDefinition::factory(),
            'contract_id' => EmploymentContract::factory(),
            'type' => 'earning',
            'quantity' => fake()->randomFloat(4, 1, 30),
            'rate' => fake()->randomFloat(4, 10000, 100000),
            'amount' => fake()->randomFloat(2, 10000, 3000000),
        ];
    }
}
