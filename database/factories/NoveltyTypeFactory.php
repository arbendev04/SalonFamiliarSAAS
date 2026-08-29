<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\NoveltyType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NoveltyType>
 */
class NoveltyTypeFactory extends Factory
{
    protected $model = NoveltyType::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'code' => fake()->unique()->lexify('NOVELTY_????'),
            'name' => fake()->words(3, true),
            // Most novelty types remove expected presence from the
            // employee's schedule (affects_time_calc), but payroll effects
            // are usually derived downstream from the time calculation
            // rather than being a direct property of the novelty itself, so
            // affects_payroll defaults to false.
            'affects_time_calc' => true,
            'affects_payroll' => false,
        ];
    }
}
