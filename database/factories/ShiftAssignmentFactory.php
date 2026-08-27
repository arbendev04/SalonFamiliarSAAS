<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Employee;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShiftAssignment>
 */
class ShiftAssignmentFactory extends Factory
{
    protected $model = ShiftAssignment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'shift_id' => Shift::factory(),
            'employee_id' => Employee::factory(),
            'status' => 'assigned',
        ];
    }
}
