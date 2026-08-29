<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\PayrollPeriod;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PayrollPeriod>
 */
class PayrollPeriodFactory extends Factory
{
    protected $model = PayrollPeriod::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = fake()->dateTimeBetween('-1 month', 'now');
        $end = (clone $start)->modify('+14 days');

        return [
            'company_id' => Company::factory(),
            // Quincenal is this project's priority period type (ADR-008).
            'period_type' => 'biweekly',
            'start_date' => $start->format('Y-m-d'),
            'end_date' => $end->format('Y-m-d'),
            'status' => 'open',
            'closed_by' => null,
            'closed_at' => null,
        ];
    }
}
