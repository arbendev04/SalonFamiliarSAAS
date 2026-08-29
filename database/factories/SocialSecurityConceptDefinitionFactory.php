<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\SocialSecurityConceptDefinition;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SocialSecurityConceptDefinition>
 */
class SocialSecurityConceptDefinitionFactory extends Factory
{
    protected $model = SocialSecurityConceptDefinition::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'code' => fake()->unique()->lexify('CONCEPT_????'),
            'name' => 'Concepto de prueba',
            'entity_type' => 'CATEGORY_A',
        ];
    }
}
