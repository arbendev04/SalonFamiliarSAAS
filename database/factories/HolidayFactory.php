<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Holiday;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Holiday>
 */
class HolidayFactory extends Factory
{
    protected $model = Holiday::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'date' => fake()->dateTimeBetween('+1 day', '+1 year')->format('Y-m-d'),
            'name' => fake()->words(3, true),
        ];
    }
}
