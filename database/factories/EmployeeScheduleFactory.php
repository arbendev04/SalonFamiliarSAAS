<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeSchedule;
use App\Models\WorkScheduleTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmployeeSchedule>
 */
class EmployeeScheduleFactory extends Factory
{
    protected $model = EmployeeSchedule::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'employee_id' => Employee::factory(),
            'template_id' => WorkScheduleTemplate::factory(),
            'effective_from' => fake()->dateTimeBetween('-1 year', 'now')->format('Y-m-d'),
            'effective_to' => null,
        ];
    }
}
