<?php

namespace Database\Seeders;

use App\Models\PayrollConceptDefinition;
use Illuminate\Database\Seeder;

/**
 * Seeds the platform-default (company_id = null) catalog rows for the payroll
 * concept catalog: BASE_SALARY, OVERTIME (earnings, .ai/10-PAYROLL.md Fase 9)
 * and LOAN, GARNISHMENT, SOCIAL_SECURITY (deductions — SOCIAL_SECURITY is a
 * generic bucket label added in Fase 10, never a real entity name or rate;
 * see .ai/11-SOCIAL-SECURITY.md). `PayrollCalculationService` resolves lines
 * against these codes.
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
        'SOCIAL_SECURITY' => ['name' => 'Aportes de seguridad social', 'type' => 'deduction', 'calculation_method' => 'percentage'],
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
