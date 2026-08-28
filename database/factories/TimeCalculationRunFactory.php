<?php

namespace Database\Factories;

use App\Models\AttendanceRecord;
use App\Models\Company;
use App\Models\Employee;
use App\Models\LaborRuleVersion;
use App\Models\TimeCalculationRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TimeCalculationRun>
 */
class TimeCalculationRunFactory extends Factory
{
    protected $model = TimeCalculationRun::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'employee_id' => Employee::factory(),
            'date' => fake()->dateTimeBetween('-1 month', 'now')->format('Y-m-d'),
            'rule_version_id' => LaborRuleVersion::factory(),
            'inputs_hash' => fake()->sha256(),
            'output_ref' => AttendanceRecord::factory(),
        ];
    }
}
