<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\EmploymentContract;
use App\Models\SalaryHistory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SalaryHistory>
 */
class SalaryHistoryFactory extends Factory
{
    protected $model = SalaryHistory::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'contract_id' => EmploymentContract::factory(),
            'effective_from' => fake()->dateTimeBetween('-2 years', '-1 year')->format('Y-m-d'),
            'effective_to' => null,
            'base_salary' => fake()->randomFloat(2, 1300000, 4000000),
            'reason' => 'contrato inicial',
        ];
    }
}
