<?php

namespace Tests\Unit\Pdf;

use Tests\TestCase;

class PayrollReceiptBladeTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function fixtureData(array $overrides = []): array
    {
        return array_replace_recursive([
            'company' => [
                'legal_name' => 'Salón Familiar SAS',
                'tax_id' => '900123456-7',
            ],
            'branch' => [
                'name' => 'Sede Norte',
            ],
            'employee' => [
                'full_name' => 'Juana Pérez',
                'document_type' => 'CC',
                'national_id' => '1020304050',
            ],
            'period' => [
                'start_date' => '2026-08-01',
                'end_date' => '2026-08-15',
            ],
            'lines' => [
                [
                    'type' => 'earning',
                    'description' => 'Salario base',
                    'quantity' => 15.0,
                    'rate' => 50000.0,
                    'amount' => 750000.0,
                ],
                [
                    'type' => 'earning',
                    'description' => 'Horas extra',
                    'quantity' => 2.0,
                    'rate' => 25000.0,
                    'amount' => 50000.0,
                ],
                [
                    'type' => 'deduction',
                    'description' => 'Salud',
                    'quantity' => null,
                    'rate' => null,
                    'amount' => 32000.0,
                ],
            ],
            'totals' => [
                'gross' => 800000.0,
                'deductions' => 32000.0,
                'net' => 768000.0,
            ],
            'observations' => [],
            'version' => 1,
            'generated_at' => '2026-08-20 10:30:00',
        ], $overrides);
    }

    public function test_renders_all_required_sections_with_observations(): void
    {
        $data = $this->fixtureData([
            'observations' => [
                [
                    'reason' => 'Corrección de horas extra',
                    'corrected_value' => 55000.0,
                ],
                [
                    'reason' => 'Ajuste manual sin valor asociado',
                    'corrected_value' => null,
                ],
            ],
            'version' => 2,
        ]);

        $html = view('pdf.payroll-receipt', ['data' => $data])->render();

        // Company / branch header
        $this->assertStringContainsString('Salón Familiar SAS', $html);
        $this->assertStringContainsString('900123456-7', $html);
        $this->assertStringContainsString('Sede Norte', $html);

        // Employee block
        $this->assertStringContainsString('Juana Pérez', $html);
        $this->assertStringContainsString('CC', $html);
        $this->assertStringContainsString('1020304050', $html);

        // Period
        $this->assertStringContainsString('2026-08-01', $html);
        $this->assertStringContainsString('2026-08-15', $html);

        // Line items
        $this->assertStringContainsString('Salario base', $html);
        $this->assertStringContainsString('Horas extra', $html);
        $this->assertStringContainsString('Salud', $html);

        // Totals
        $this->assertStringContainsString('Devengado', $html);
        $this->assertStringContainsString('Deducido', $html);
        $this->assertStringContainsString('Neto', $html);
        $this->assertStringContainsString(number_format(800000.0, 2), $html);
        $this->assertStringContainsString(number_format(32000.0, 2), $html);
        $this->assertStringContainsString(number_format(768000.0, 2), $html);

        // Observations
        $this->assertStringContainsString('Corrección de horas extra', $html);
        $this->assertStringContainsString(number_format(55000.0, 2), $html);
        $this->assertStringContainsString('Ajuste manual sin valor asociado', $html);

        // Footer
        $this->assertStringContainsString('2026-08-20 10:30:00', $html);
        $this->assertStringContainsString('2', $html);
    }

    public function test_renders_empty_state_when_there_are_no_observations(): void
    {
        $data = $this->fixtureData([
            'branch' => null,
            'observations' => [],
            'version' => 1,
            'generated_at' => '2026-08-15 09:00:00',
        ]);

        $html = view('pdf.payroll-receipt', ['data' => $data])->render();

        $this->assertStringContainsString('Salón Familiar SAS', $html);
        $this->assertStringNotContainsString('Sede Norte', $html);
        $this->assertStringContainsString('Sin observaciones', $html);
        $this->assertStringContainsString('2026-08-15 09:00:00', $html);
    }
}
