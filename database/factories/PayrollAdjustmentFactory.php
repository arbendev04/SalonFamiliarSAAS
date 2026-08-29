<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\PayrollAdjustment;
use App\Models\PayrollEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PayrollAdjustment>
 */
class PayrollAdjustmentFactory extends Factory
{
    protected $model = PayrollAdjustment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'payroll_entry_id' => PayrollEntry::factory(),
            'mechanism' => 'next_period',
            'original_value' => null,
            'corrected_value' => ['amount' => fake()->randomFloat(2, 10000, 500000)],
            'reason' => fake()->sentence(),
            'created_by' => User::factory(),
            'applied_in_period_id' => null,
        ];
    }
}
