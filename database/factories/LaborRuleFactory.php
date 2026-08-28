<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\LaborRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LaborRule>
 */
class LaborRuleFactory extends Factory
{
    protected $model = LaborRule::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'rule_type' => 'STANDARD_WORKWEEK',
            'name' => 'Jornada laboral estándar',
        ];
    }
}
