<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Shift;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Shift>
 */
class ShiftFactory extends Factory
{
    protected $model = Shift::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $date = fake()->dateTimeBetween('-1 month', '+1 month')->format('Y-m-d');

        return [
            'company_id' => Company::factory(),
            'branch_id' => null,
            'template_id' => null,
            'date' => $date,
            'start_datetime' => $date.' 06:00:00',
            'end_datetime' => $date.' 14:00:00',
            'type' => 'regular',
            'crosses_midnight' => false,
            'source' => 'manual',
        ];
    }
}
