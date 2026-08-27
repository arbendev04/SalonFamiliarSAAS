<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\WorkScheduleDay;
use App\Models\WorkScheduleTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkScheduleDay>
 */
class WorkScheduleDayFactory extends Factory
{
    protected $model = WorkScheduleDay::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'template_id' => WorkScheduleTemplate::factory(),
            'day_of_week' => fake()->numberBetween(0, 6),
            'start_time' => '06:00:00',
            'end_time' => '14:00:00',
            'crosses_midnight' => false,
        ];
    }
}
