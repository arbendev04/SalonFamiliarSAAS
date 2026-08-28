<?php

namespace Database\Factories;

use App\Models\AttendanceAdjustment;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AttendanceAdjustment>
 */
class AttendanceAdjustmentFactory extends Factory
{
    protected $model = AttendanceAdjustment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'original_event_id' => null,
            'employee_id' => Employee::factory(),
            'type' => 'modify',
            'original_value' => null,
            'corrected_value' => ['event_datetime' => '2026-02-10 08:00:00'],
            'reason' => fake()->sentence(),
            'requested_by' => User::factory(),
            'approved_by' => null,
            'status' => 'pending',
        ];
    }
}
