<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\PayrollEntry;
use App\Models\SocialSecurityConceptDefinition;
use App\Models\SocialSecurityContribution;
use App\Models\SocialSecurityEntity;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SocialSecurityContribution>
 */
class SocialSecurityContributionFactory extends Factory
{
    protected $model = SocialSecurityContribution::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $periodFrom = fake()->dateTimeBetween('-1 year', '-1 month')->format('Y-m-d');

        return [
            'company_id' => Company::factory(),
            'payroll_entry_id' => PayrollEntry::factory(),
            'entity_id' => SocialSecurityEntity::factory(),
            'concept_id' => SocialSecurityConceptDefinition::factory(),
            'period_from' => $periodFrom,
            'period_to' => $periodFrom,
            // Round, clearly-arbitrary numbers — not a real contribution
            // rate applied to a realistic salary. See
            // composed-knitting-dusk.md's fixture-discipline constraint.
            'base_amount' => 1000000,
            'employee_amount' => 100000,
            'employer_amount' => 200000,
        ];
    }
}
