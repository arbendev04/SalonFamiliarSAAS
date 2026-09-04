<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\GeneratedDocument;
use App\Models\PayrollEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GeneratedDocument>
 */
class GeneratedDocumentFactory extends Factory
{
    protected $model = GeneratedDocument::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'type' => 'payroll_receipt',
            'reference_entity_type' => 'payroll_entry',
            'reference_entity_id' => PayrollEntry::factory(),
            'storage_ref' => fn (array $attributes) => "receipts/{$attributes['company_id']}/".fake()->uuid().'/v1.pdf',
            'generated_by' => User::factory(),
            'version' => 1,
        ];
    }
}
