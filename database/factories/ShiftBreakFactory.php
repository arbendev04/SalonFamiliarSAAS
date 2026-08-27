<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Shift;
use App\Models\ShiftBreak;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShiftBreak>
 */
class ShiftBreakFactory extends Factory
{
    protected $model = ShiftBreak::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $date = fake()->dateTimeBetween('-1 month', '+1 month')->format('Y-m-d');

        return [
            'company_id' => Company::factory(),
            'shift_id' => Shift::factory(),
            'planned_start' => $date.' 12:00:00',
            'planned_end' => $date.' 13:00:00',
            'paid' => false,
        ];
    }
}
