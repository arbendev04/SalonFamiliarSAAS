<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Employee;
use App\Models\OvertimeRecord;
use App\Models\Shift;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OvertimeRecord>
 */
class OvertimeRecordFactory extends Factory
{
    protected $model = OvertimeRecord::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'employee_id' => Employee::factory(),
            'shift_id' => Shift::factory(),
            'detected_minutes' => fake()->numberBetween(15, 120),
            'requested_minutes' => null,
            'authorized_minutes' => null,
            // Lowercase snake_case, matching leave_records/attendance_adjustments
            // status casing in this codebase (not the uppercase shown in
            // .ai/04-DOMAIN-MODEL.md's prose, which is conceptual only).
            'status' => 'detected',
        ];
    }
}
