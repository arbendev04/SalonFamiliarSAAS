<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\PayrollConceptDefinition;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PayrollConceptDefinition>
 */
class PayrollConceptDefinitionFactory extends Factory
{
    protected $model = PayrollConceptDefinition::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'code' => fake()->unique()->lexify('CONCEPT_????'),
            'name' => fake()->words(3, true),
            'type' => 'earning',
            'calculation_method' => 'fixed',
        ];
    }
}
