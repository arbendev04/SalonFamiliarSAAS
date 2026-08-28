<?php

namespace Database\Factories;

use App\Models\AttendanceEvent;
use App\Models\Company;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AttendanceEvent>
 */
class AttendanceEventFactory extends Factory
{
    protected $model = AttendanceEvent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'employee_id' => Employee::factory(),
            'event_type' => 'clock_in',
            'event_datetime' => fake()->dateTimeBetween('-1 month', 'now'),
            'source' => 'web',
            'device_id' => null,
            'metadata' => null,
        ];
    }
}
