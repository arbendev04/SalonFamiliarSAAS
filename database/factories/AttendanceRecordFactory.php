<?php

namespace Database\Factories;

use App\Models\AttendanceRecord;
use App\Models\Company;
use App\Models\Employee;
use App\Models\LaborRuleVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AttendanceRecord>
 */
class AttendanceRecordFactory extends Factory
{
    protected $model = AttendanceRecord::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'employee_id' => Employee::factory(),
            'date' => fake()->dateTimeBetween('-1 month', 'now')->format('Y-m-d'),
            'planned_json' => ['planned_minutes' => 480],
            'worked_json' => ['worked_minutes' => 480],
            'ordinary_minutes' => 480,
            'overtime_candidate_minutes' => 0,
            'missing_minutes' => 0,
            'rule_version_id' => LaborRuleVersion::factory(),
            'calculated_at' => now(),
        ];
    }
}
