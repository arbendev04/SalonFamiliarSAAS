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
                // Money-side keys, added in Fase 9 (Payroll) as the first real
                // consumer of labor_rule_versions.parameters beyond time-only
                // classification. Container-schema addition only — no legal
                // value is asserted by these defaults, callers must configure
                // their own per rule #15 in .ai/AGENTS.md.
                'monthly_hours_divisor' => 240,
                'overtime_surcharge_pct' => 0.25,
            ],
            'created_by' => null,
        ];
    }
}
