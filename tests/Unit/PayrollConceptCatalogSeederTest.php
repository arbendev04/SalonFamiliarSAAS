<?php

namespace Tests\Unit;

use App\Models\PayrollConceptDefinition;
use Database\Seeders\PayrollConceptCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayrollConceptCatalogSeederTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @var array<string, array{name: string, type: string, calculation_method: string}>
     */
    private const EXPECTED_CATALOG = [
        'BASE_SALARY' => ['name' => 'Salario base', 'type' => 'earning', 'calculation_method' => 'fixed'],
        'OVERTIME' => ['name' => 'Horas extra', 'type' => 'earning', 'calculation_method' => 'hourly'],
        'LOAN' => ['name' => 'Préstamo', 'type' => 'deduction', 'calculation_method' => 'fixed'],
        'GARNISHMENT' => ['name' => 'Embargo', 'type' => 'deduction', 'calculation_method' => 'fixed'],
        'SOCIAL_SECURITY' => ['name' => 'Aportes de seguridad social', 'type' => 'deduction', 'calculation_method' => 'percentage'],
    ];

    public function test_seeds_exactly_5_platform_default_payroll_concept_definitions_with_expected_attributes()
    {
        $this->seed(PayrollConceptCatalogSeeder::class);

        $concepts = PayrollConceptDefinition::query()->whereNull('company_id')->get();

        $this->assertCount(5, $concepts);

        foreach (self::EXPECTED_CATALOG as $code => $attributes) {
            $this->assertTrue(
                $concepts->contains(fn (PayrollConceptDefinition $concept) => $concept->code === $code
                    && $concept->name === $attributes['name']
                    && $concept->type === $attributes['type']
                    && $concept->calculation_method === $attributes['calculation_method']),
                "Expected a payroll_concept_definitions row with code={$code}, name={$attributes['name']}, type={$attributes['type']}, calculation_method={$attributes['calculation_method']}.",
            );
        }
    }

    public function test_running_the_seeder_twice_does_not_create_duplicates()
    {
        $this->seed(PayrollConceptCatalogSeeder::class);
        $this->seed(PayrollConceptCatalogSeeder::class);

        $this->assertSame(5, PayrollConceptDefinition::query()->whereNull('company_id')->count());
    }

    public function test_seeds_two_earning_concepts_and_three_deduction_concepts()
    {
        $this->seed(PayrollConceptCatalogSeeder::class);

        $concepts = PayrollConceptDefinition::query()->whereNull('company_id')->get();

        $this->assertCount(2, $concepts->where('type', 'earning'));
        $this->assertCount(3, $concepts->where('type', 'deduction'));
    }
}
