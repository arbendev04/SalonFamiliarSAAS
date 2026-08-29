<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\SocialSecurityEntity;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SocialSecurityEntity>
 */
class SocialSecurityEntityFactory extends Factory
{
    protected $model = SocialSecurityEntity::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'type' => 'CATEGORY_A',
            'name' => 'Entidad de prueba',
            'code' => fake()->unique()->regexify('[A-Z]{3}-[0-9]{2}'),
        ];
    }
}
