<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Employee;
use App\Models\EmploymentContract;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmploymentContract>
 */
class EmploymentContractFactory extends Factory
{
    protected $model = EmploymentContract::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'employee_id' => Employee::factory(),
            'position_id' => null,
            'contract_type' => 'indefinido',
            'start_date' => fake()->dateTimeBetween('-2 years', '-1 year')->format('Y-m-d'),
            'end_date' => null,
            'base_salary' => fake()->randomFloat(2, 1300000, 4000000),
            'status' => 'active',
        ];
    }
}
