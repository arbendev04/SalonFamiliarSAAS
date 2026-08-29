<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Employee;
use App\Models\LeaveRecord;
use App\Models\LeaveType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LeaveRecord>
 */
class LeaveRecordFactory extends Factory
{
    protected $model = LeaveRecord::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'employee_id' => Employee::factory(),
            'leave_type_id' => LeaveType::factory(),
            'date_from' => '2026-02-10',
            'date_to' => '2026-02-10',
            'status' => 'pending',
            'approved_by' => null,
            'document_ref' => null,
            'reason' => fake()->sentence(),
        ];
    }
}
