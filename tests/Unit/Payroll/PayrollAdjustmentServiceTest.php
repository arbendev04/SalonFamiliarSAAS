<?php

namespace Tests\Unit\Payroll;

use App\Exceptions\InvalidPayrollPeriodStatusException;
use App\Exceptions\NoOpenNextPayrollPeriodException;
use App\Exceptions\PayrollAdjustmentImmutableException;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmploymentContract;
use App\Models\PayrollAdjustment;
use App\Models\PayrollConceptDefinition;
use App\Models\PayrollEntry;
use App\Models\PayrollEntryLine;
use App\Models\PayrollPeriod;
use App\Models\User;
use App\Services\Payroll\PayrollAdjustmentService;
use App\Services\Tenancy\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers both ADR-026 correction mechanisms built on top of a CLOSED
 * payroll_entries row (Fase 9, commit 12). adjustInNextPeriod() — the
 * DEFAULT mechanism per the plan ("el camino por defecto, el que se prueba a
 * fondo") — gets full coverage; recordReopenCorrection() gets a lighter
 * pass since its actual entry mutation happens elsewhere
 * (PayrollCalculationService, already covered).
 */
class PayrollAdjustmentServiceTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $actor;

    private PayrollAdjustmentService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        app(CurrentCompany::class)->set($this->company);

        $this->actor = User::factory()->create();
        $this->service = app(PayrollAdjustmentService::class);
    }

    /**
     * Builds a CLOSED payroll_entries row with one earning line
     * (amount 500000), for one employee, in a period running
     * 2026-01-01..2026-01-15.
     */
    private function closedEntry(): PayrollEntry
    {
        $employee = Employee::factory()->create(['company_id' => $this->company->id]);
        $contract = EmploymentContract::factory()->create([
            'company_id' => $this->company->id,
            'employee_id' => $employee->id,
        ]);

        $period = PayrollPeriod::factory()->create([
            'company_id' => $this->company->id,
            'status' => 'closed',
            'start_date' => '2026-01-01',
            'end_date' => '2026-01-15',
        ]);

        $entry = PayrollEntry::factory()->create([
            'company_id' => $this->company->id,
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
            'contract_id' => $contract->id,
            'status' => 'calculated',
            'gross_total' => 500000,
            'deductions_total' => 0,
            'net_total' => 500000,
        ]);

        $concept = PayrollConceptDefinition::factory()->create(['company_id' => $this->company->id]);

        PayrollEntryLine::factory()->create([
            'company_id' => $this->company->id,
            'payroll_entry_id' => $entry->id,
            'concept_id' => $concept->id,
            'contract_id' => $contract->id,
            'type' => 'earning',
            'quantity' => 15,
            'rate' => 33333.3333,
            'amount' => 500000,
        ]);

        return $entry;
    }

    /**
     * Builds a payroll_periods row for the same company, right after
     * $closedEntry's period, and — unless $withEntry is false — a
     * PayrollEntry for the same employee in it (gross_total 300000,
     * deductions_total 0, net_total 300000).
     */
    private function nextPeriod(PayrollEntry $closedEntry, string $status = 'open', bool $withEntry = true): PayrollPeriod
    {
        $targetPeriod = PayrollPeriod::factory()->create([
            'company_id' => $this->company->id,
            'status' => $status,
            'start_date' => '2026-01-16',
            'end_date' => '2026-01-31',
        ]);

        if ($withEntry) {
            PayrollEntry::factory()->create([
                'company_id' => $this->company->id,
                'payroll_period_id' => $targetPeriod->id,
                'employee_id' => $closedEntry->employee_id,
                'contract_id' => $closedEntry->contract_id,
                'status' => 'calculated',
                'gross_total' => 300000,
                'deductions_total' => 0,
                'net_total' => 300000,
            ]);
        }

        return $targetPeriod;
    }

    // ----------------------------------------------------------------
    // adjustInNextPeriod() — happy path
    // ----------------------------------------------------------------

    public function test_adjust_in_next_period_creates_a_line_on_the_target_entry_and_updates_its_totals()
    {
        $closedEntry = $this->closedEntry();
        $targetPeriod = $this->nextPeriod($closedEntry);
        $concept = PayrollConceptDefinition::factory()->create(['company_id' => $this->company->id]);

        $adjustment = $this->service->adjustInNextPeriod(
            closedEntry: $closedEntry,
            createdBy: $this->actor,
            conceptId: $concept->id,
            amount: 50000.0,
            type: 'earning',
            reason: 'Horas extra omitidas en el cálculo original.',
        );

        $targetEntry = PayrollEntry::query()
            ->where('payroll_period_id', $targetPeriod->id)
            ->where('employee_id', $closedEntry->employee_id)
            ->first();

        // The new line landed on the TARGET entry.
        $newLine = PayrollEntryLine::query()
            ->where('payroll_entry_id', $targetEntry->id)
            ->where('concept_id', $concept->id)
            ->first();

        $this->assertNotNull($newLine);
        $this->assertSame('earning', $newLine->type);
        $this->assertNull($newLine->contract_id);
        $this->assertNull($newLine->quantity);
        $this->assertNull($newLine->rate);
        $this->assertEqualsWithDelta(50000.0, (float) $newLine->amount, 0.0001);

        // Totals: gross 300000 + 50000 = 350000, deductions unchanged (0),
        // net = 350000 - 0.
        $fresh = $targetEntry->fresh();
        $this->assertEqualsWithDelta(350000.0, (float) $fresh->gross_total, 0.0001);
        $this->assertEqualsWithDelta(0.0, (float) $fresh->deductions_total, 0.0001);
        $this->assertEqualsWithDelta(350000.0, (float) $fresh->net_total, 0.0001);

        // The PayrollAdjustment row: mechanism/payroll_entry_id/applied_in_period_id.
        $this->assertSame('next_period', $adjustment->mechanism);
        $this->assertSame($closedEntry->id, $adjustment->payroll_entry_id);
        $this->assertSame($targetPeriod->id, $adjustment->applied_in_period_id);
        $this->assertNull($adjustment->original_value);
        $this->assertSame($concept->id, $adjustment->corrected_value['concept_id']);
        $this->assertEqualsWithDelta(50000.0, (float) $adjustment->corrected_value['amount'], 0.0001);
        $this->assertSame('earning', $adjustment->corrected_value['type']);

        // Exactly one audit row.
        $this->assertSame(
            1,
            AuditLog::query()->where('entity_id', $adjustment->id)->where('action', 'payroll_adjustment.created')->count(),
        );
    }

    public function test_adjust_in_next_period_with_a_deduction_type_updates_deductions_total_and_net_total()
    {
        $closedEntry = $this->closedEntry();
        $this->nextPeriod($closedEntry);
        $concept = PayrollConceptDefinition::factory()->create(['company_id' => $this->company->id]);

        $adjustment = $this->service->adjustInNextPeriod(
            closedEntry: $closedEntry,
            createdBy: $this->actor,
            conceptId: $concept->id,
            amount: 20000.0,
            type: 'deduction',
            reason: 'Descuento aplicado de menos en el periodo cerrado.',
        );

        $targetEntry = PayrollEntry::query()->where('id', '!=', $closedEntry->id)->where('employee_id', $closedEntry->employee_id)->first();
        $fresh = $targetEntry->fresh();

        $this->assertEqualsWithDelta(300000.0, (float) $fresh->gross_total, 0.0001);
        $this->assertEqualsWithDelta(20000.0, (float) $fresh->deductions_total, 0.0001);
        $this->assertEqualsWithDelta(280000.0, (float) $fresh->net_total, 0.0001);
        $this->assertSame('deduction', $adjustment->corrected_value['type']);
    }

    // ----------------------------------------------------------------
    // adjustInNextPeriod() — the original closed entry is untouched
    // ----------------------------------------------------------------

    public function test_adjust_in_next_period_never_touches_the_original_closed_entry_or_its_lines()
    {
        $closedEntry = $this->closedEntry();
        $this->nextPeriod($closedEntry);
        $concept = PayrollConceptDefinition::factory()->create(['company_id' => $this->company->id]);

        $originalEntrySnapshot = $closedEntry->fresh()->toArray();
        $originalLinesSnapshot = PayrollEntryLine::query()
            ->where('payroll_entry_id', $closedEntry->id)
            ->orderBy('id')
            ->get()
            ->toArray();

        $this->service->adjustInNextPeriod(
            closedEntry: $closedEntry,
            createdBy: $this->actor,
            conceptId: $concept->id,
            amount: 75000.0,
            type: 'earning',
            reason: 'Corrección de horas extra.',
        );

        $reloadedEntry = PayrollEntry::query()->findOrFail($closedEntry->id)->toArray();
        $reloadedLines = PayrollEntryLine::query()
            ->where('payroll_entry_id', $closedEntry->id)
            ->orderBy('id')
            ->get()
            ->toArray();

        $this->assertSame($originalEntrySnapshot, $reloadedEntry);
        $this->assertSame($originalLinesSnapshot, $reloadedLines);
        // Still exactly the one original line — nothing appended here.
        $this->assertCount(1, $reloadedLines);
    }

    // ----------------------------------------------------------------
    // adjustInNextPeriod() — guards
    // ----------------------------------------------------------------

    public function test_adjust_in_next_period_on_a_non_closed_entry_throws()
    {
        $employee = Employee::factory()->create(['company_id' => $this->company->id]);
        $period = PayrollPeriod::factory()->create([
            'company_id' => $this->company->id,
            'status' => 'calculated',
        ]);
        $entry = PayrollEntry::factory()->create([
            'company_id' => $this->company->id,
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
            'status' => 'calculated',
        ]);
        $concept = PayrollConceptDefinition::factory()->create(['company_id' => $this->company->id]);

        $this->expectException(InvalidPayrollPeriodStatusException::class);

        $this->service->adjustInNextPeriod(
            closedEntry: $entry,
            createdBy: $this->actor,
            conceptId: $concept->id,
            amount: 10000.0,
            type: 'earning',
            reason: 'No debería aplicarse.',
        );
    }

    public function test_adjust_in_next_period_with_no_future_period_at_all_throws_no_open_next_payroll_period_exception()
    {
        $closedEntry = $this->closedEntry();
        // No future period created at all.
        $concept = PayrollConceptDefinition::factory()->create(['company_id' => $this->company->id]);

        $this->expectException(NoOpenNextPayrollPeriodException::class);

        $this->service->adjustInNextPeriod(
            closedEntry: $closedEntry,
            createdBy: $this->actor,
            conceptId: $concept->id,
            amount: 10000.0,
            type: 'earning',
            reason: 'No debería aplicarse.',
        );
    }

    public function test_adjust_in_next_period_when_the_next_period_has_no_payroll_entry_yet_for_the_employee_throws()
    {
        $closedEntry = $this->closedEntry();
        // Next period exists (status=open) but never calculated for this employee.
        $this->nextPeriod($closedEntry, status: 'open', withEntry: false);
        $concept = PayrollConceptDefinition::factory()->create(['company_id' => $this->company->id]);

        $this->expectException(NoOpenNextPayrollPeriodException::class);

        $this->service->adjustInNextPeriod(
            closedEntry: $closedEntry,
            createdBy: $this->actor,
            conceptId: $concept->id,
            amount: 10000.0,
            type: 'earning',
            reason: 'No debería aplicarse: el periodo destino nunca fue calculado para este empleado.',
        );
    }

    public function test_adjust_in_next_period_ignores_a_future_period_that_is_already_closed()
    {
        $closedEntry = $this->closedEntry();
        // The only future period exists but is itself closed — must be
        // treated as if no valid target period exists at all.
        $this->nextPeriod($closedEntry, status: 'closed', withEntry: true);
        $concept = PayrollConceptDefinition::factory()->create(['company_id' => $this->company->id]);

        $this->expectException(NoOpenNextPayrollPeriodException::class);

        $this->service->adjustInNextPeriod(
            closedEntry: $closedEntry,
            createdBy: $this->actor,
            conceptId: $concept->id,
            amount: 10000.0,
            type: 'earning',
            reason: 'No debería aplicarse: el único periodo futuro ya está cerrado.',
        );
    }

    // ----------------------------------------------------------------
    // recordReopenCorrection()
    // ----------------------------------------------------------------

    public function test_record_reopen_correction_creates_the_adjustment_row_with_one_audit_row()
    {
        $closedEntry = $this->closedEntry();

        $adjustment = $this->service->recordReopenCorrection(
            entry: $closedEntry,
            createdBy: $this->actor,
            originalValue: ['amount' => 500000],
            correctedValue: ['amount' => 550000],
            reason: 'Reapertura por horas extra mal autorizadas.',
        );

        $this->assertSame('reopen', $adjustment->mechanism);
        $this->assertSame($closedEntry->id, $adjustment->payroll_entry_id);
        $this->assertNull($adjustment->applied_in_period_id);
        $this->assertSame(['amount' => 500000], $adjustment->original_value);
        $this->assertSame(['amount' => 550000], $adjustment->corrected_value);

        $this->assertSame(
            1,
            AuditLog::query()->where('entity_id', $adjustment->id)->where('action', 'payroll_adjustment.created')->count(),
        );
    }

    // ----------------------------------------------------------------
    // Immutability regression (commit 2's guard)
    // ----------------------------------------------------------------

    public function test_updating_a_payroll_adjustment_created_by_adjust_in_next_period_throws()
    {
        $closedEntry = $this->closedEntry();
        $this->nextPeriod($closedEntry);
        $concept = PayrollConceptDefinition::factory()->create(['company_id' => $this->company->id]);

        $adjustment = $this->service->adjustInNextPeriod(
            closedEntry: $closedEntry,
            createdBy: $this->actor,
            conceptId: $concept->id,
            amount: 10000.0,
            type: 'earning',
            reason: 'Motivo válido.',
        );

        $this->expectException(PayrollAdjustmentImmutableException::class);

        $adjustment->update(['reason' => 'Intento de edición.']);
    }

    public function test_deleting_a_payroll_adjustment_created_by_record_reopen_correction_throws()
    {
        $closedEntry = $this->closedEntry();

        $adjustment = $this->service->recordReopenCorrection(
            entry: $closedEntry,
            createdBy: $this->actor,
            originalValue: null,
            correctedValue: ['amount' => 550000],
            reason: 'Motivo válido.',
        );

        $this->expectException(PayrollAdjustmentImmutableException::class);

        $adjustment->delete();
    }

    public function test_mass_update_through_the_query_builder_on_a_payroll_adjustment_throws()
    {
        $closedEntry = $this->closedEntry();

        $this->service->recordReopenCorrection(
            entry: $closedEntry,
            createdBy: $this->actor,
            originalValue: null,
            correctedValue: ['amount' => 550000],
            reason: 'Motivo válido.',
        );

        $this->expectException(PayrollAdjustmentImmutableException::class);

        PayrollAdjustment::query()->where('payroll_entry_id', $closedEntry->id)->update(['reason' => 'Intento de edición masiva.']);
    }
}
