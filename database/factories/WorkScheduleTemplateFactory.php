<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\WorkScheduleTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkScheduleTemplate>
 */
class WorkScheduleTemplateFactory extends Factory
{
    protected $model = WorkScheduleTemplate::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'name' => 'Turno '.fake()->unique()->word(),
        ];
    }
}
