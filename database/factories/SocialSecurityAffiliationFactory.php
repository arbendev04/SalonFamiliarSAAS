<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Employee;
use App\Models\SocialSecurityAffiliation;
use App\Models\SocialSecurityEntity;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SocialSecurityAffiliation>
 */
class SocialSecurityAffiliationFactory extends Factory
{
    protected $model = SocialSecurityAffiliation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'employee_id' => Employee::factory(),
            'entity_id' => SocialSecurityEntity::factory(),
            'entity_type' => 'CATEGORY_A',
            'affiliation_number' => fake()->unique()->numerify('AFF-#######'),
            'start_date' => fake()->dateTimeBetween('-2 years', '-1 year')->format('Y-m-d'),
            'end_date' => null,
        ];
    }
}
