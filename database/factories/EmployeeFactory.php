<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Employee>
 */
class EmployeeFactory extends Factory
{
    protected $model = Employee::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'branch_id' => null,
            'full_name' => fake()->name(),
            'document_type' => 'CC',
            'national_id' => fake()->unique()->numerify('##########'),
            'birth_date' => fake()->dateTimeBetween('-55 years', '-18 years')->format('Y-m-d'),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->numerify('3#########'),
            'hire_date' => fake()->dateTimeBetween('-3 years', 'now')->format('Y-m-d'),
            'status' => 'active',
        ];
    }
}
