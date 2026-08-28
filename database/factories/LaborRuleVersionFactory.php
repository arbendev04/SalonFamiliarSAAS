<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\LaborRule;
use App\Models\LaborRuleVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LaborRuleVersion>
 */
class LaborRuleVersionFactory extends Factory
{
    protected $model = LaborRuleVersion::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'labor_rule_id' => LaborRule::factory(),
            'effective_from' => fake()->dateTimeBetween('-1 year', 'now')->format('Y-m-d'),
            'effective_to' => null,
            'parameters' => [
                'tolerance_minutes' => 15,
                'rounding_minutes' => 5,
            ],
            'created_by' => null,
        ];
    }
}
