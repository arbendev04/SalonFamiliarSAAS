<?php

namespace Database\Seeders;

use App\Models\PayrollConceptDefinition;
use Illuminate\Database\Seeder;

/**
 * Seeds the platform-default (company_id = null) catalog rows for the payroll
 * concept catalog, scoped to the 4 codes named in .ai/10-PAYROLL.md Fase 9
 * (scoped-down 3-concept MVP): BASE_SALARY, OVERTIME (earnings) and LOAN,
 * GARNISHMENT (deductions). `PayrollCalculationService` (a later commit)
 * resolves lines against these codes.
 *
 * Follows the same DIRECTO/GLOBAL platform-default pattern as
 * EssentialNoveltyCatalogSeeder / ColombianHolidaySeeder — see
 * HasPlatformOrCompanyDefault.
 */
class PayrollConceptCatalogSeeder extends Seeder
{
    /**
     * @var array<string, array{name: string, type: string, calculation_method: string}>
     */
    private const CATALOG = [
        'BASE_SALARY' => ['name' => 'Salario base', 'type' => 'earning', 'calculation_method' => 'fixed'],
        'OVERTIME' => ['name' => 'Horas extra', 'type' => 'earning', 'calculation_method' => 'hourly'],
        'LOAN' => ['name' => 'Préstamo', 'type' => 'deduction', 'calculation_method' => 'fixed'],
        'GARNISHMENT' => ['name' => 'Embargo', 'type' => 'deduction', 'calculation_method' => 'fixed'],
    ];

    public function run(): void
    {
        foreach (self::CATALOG as $code => $attributes) {
            PayrollConceptDefinition::query()->updateOrCreate(
                ['company_id' => null, 'code' => $code],
                $attributes,
            );
        }
    }
}
