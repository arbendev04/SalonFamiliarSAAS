<?php

namespace Tests\Unit\Payroll;

use App\Exceptions\InvalidPayrollPeriodStatusException;
use App\Exceptions\UnresolvedBlockedPayrollEntriesException;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmploymentContract;
use App\Models\PayrollDeductionPlan;
use App\Models\PayrollEntry;
use App\Models\PayrollEntryLine;
use App\Models\PayrollPeriod;
use App\Models\User;
use App\Services\Payroll\PayrollPeriodService;
use App\Services\Tenancy\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Covers the period-level state machine (open -> calculated ->
 * approved(optional) -> closed -> reopened) built on top of
 * PayrollCalculationService::calculateForPeriod() (Fase 9, commit 10). No
 * PayrollPeriodController exists yet (deferred to a later commit), so this
 * exercises the service directly — same convention as
 * OvertimeRecordServiceTest/PayrollCalculationServiceTest.
 *
 * calculate() tests deliberately use a company with zero employees so
 * calculateForPeriod() returns an empty results collection without needing
 * PayrollConceptCatalogSeeder or any attendance/contract fixtures — the
 * state-machine transition and audit shape are what's under test here, not
 * the calculation engine itself (already covered by
 * PayrollCalculationServiceTest).
 */
class PayrollPeriodServiceTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $actor;

    private PayrollPeriodService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        app(CurrentCompany::class)->set($this->company);

        $this->actor = User::factory()->create();
        $this->service = app(PayrollPeriodService::class);
    }

    private function period(string $status): PayrollPeriod
    {
        return PayrollPeriod::factory()->create([
            'company_id' => $this->company->id,
            'status' => $status,
        ]);
    }

    // ----------------------------------------------------------------
    // calculate()
    // ----------------------------------------------------------------

    public function test_calculate_from_open_transitions_to_calculated_with_one_audit_row_and_a_summary_shape()
    {
        $period = $this->period('open');

        $result = $this->service->calculate($period, $this->actor);

        $this->assertSame('calculated', $result->status);
        $this->assertSame('calculated', $period->fresh()->status);

        $auditLogs = AuditLog::query()
            ->where('entity_id', $period->id)
            ->where('action', 'payroll_period.calculated')
            ->get();

        $this->assertCount(1, $auditLogs);

        $newValue = $auditLogs->first()->new_value;
        $this->assertSame('calculated', $newValue['status']);
        $this->assertSame(0, $newValue['ok_count']);
        $this->assertSame(0, $newValue['blocked_count']);
        $this->assertSame([], $newValue['blocked_employee_ids']);
    }

    public function test_calculate_is_allowed_from_calculated_approved_and_reopened_since_recalculation_is_free_until_closed()
    {
        foreach (['calculated', 'approved', 'reopened'] as $startingStatus) {
            $period = $this->period($startingStatus);

            $result = $this->service->calculate($period, $this->actor);

            $this->assertSame('calculated', $result->status, "Expected recalculation from '{$startingStatus}' to succeed.");
        }
    }

    public function test_calculate_from_closed_throws_invalid_payroll_period_status_exception()
    {
        $period = $this->period('closed');

        $this->expectException(InvalidPayrollPeriodStatusException::class);

        $this->service->calculate($period, $this->actor);
    }

    // ----------------------------------------------------------------
    // approve()
    // ----------------------------------------------------------------

    public function test_approve_from_calculated_transitions_to_approved_with_one_audit_row()
    {
        $period = $this->period('calculated');

        $result = $this->service->approve($period, $this->actor);

        $this->assertSame('approved', $result->status);
        $this->assertSame('approved', $period->fresh()->status);

        $this->assertSame(
            1,
            AuditLog::query()->where('entity_id', $period->id)->where('action', 'payroll_period.approved')->count(),
        );
    }

    public function test_approve_from_a_non_calculated_status_throws()
    {
        foreach (['open', 'approved', 'closed', 'reopened'] as $startingStatus) {
            $period = $this->period($startingStatus);

            try {
                $this->service->approve($period, $this->actor);
                $this->fail("Expected InvalidPayrollPeriodStatusException when approving from '{$startingStatus}'.");
            } catch (InvalidPayrollPeriodStatusException) {
                $this->assertTrue(true);
            }
        }
    }

    // ----------------------------------------------------------------
    // close()
    // ----------------------------------------------------------------

    public function test_close_from_calculated_with_zero_blocked_entries_succeeds_and_sets_closed_by_and_closed_at()
    {
        $period = $this->period('calculated');

        $result = $this->service->close($period, $this->actor);

        $this->assertSame('closed', $result->status);
        $this->assertSame($this->actor->id, $result->closed_by);
        $this->assertNotNull($result->closed_at);

        $fresh = $period->fresh();
        $this->assertSame('closed', $fresh->status);
        $this->assertSame($this->actor->id, $fresh->closed_by);
        $this->assertNotNull($fresh->closed_at);

        $this->assertSame(
            1,
            AuditLog::query()->where('entity_id', $period->id)->where('action', 'payroll_period.closed')->count(),
        );
    }

    public function test_close_with_an_unresolved_blocked_entry_throws_and_leaves_the_period_status_unchanged()
    {
        $period = $this->period('calculated');
        $employee = Employee::factory()->create(['company_id' => $this->company->id]);

        PayrollEntry::factory()->create([
            'company_id' => $this->company->id,
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
            'contract_id' => null,
            'status' => 'blocked',
            'gross_total' => 0,
            'deductions_total' => 0,
            'net_total' => 0,
        ]);

        try {
            $this->service->close($period, $this->actor);
            $this->fail('Expected UnresolvedBlockedPayrollEntriesException.');
        } catch (UnresolvedBlockedPayrollEntriesException) {
            // expected
        }

        $this->assertSame('calculated', $period->fresh()->status);
        $this->assertNull($period->fresh()->closed_at);
    }

    public function test_close_from_open_throws_invalid_payroll_period_status_exception()
    {
        $period = $this->period('open');

        $this->expectException(InvalidPayrollPeriodStatusException::class);

        $this->service->close($period, $this->actor);
    }

    // ----------------------------------------------------------------
    // reopen()
    // ----------------------------------------------------------------

    public function test_reopen_from_closed_transitions_to_reopened_with_the_reason_captured_in_the_audit_row()
    {
        $period = $this->period('closed');

        $result = $this->service->reopen($period, $this->actor, 'Corrección de horas extra mal autorizadas.');

        $this->assertSame('reopened', $result->status);
        $this->assertSame('reopened', $period->fresh()->status);

        $auditLog = AuditLog::query()
            ->where('entity_id', $period->id)
            ->where('action', 'payroll_period.reopened')
            ->first();

        $this->assertNotNull($auditLog);
        $this->assertSame('Corrección de horas extra mal autorizadas.', $auditLog->reason);
        $this->assertSame('Corrección de horas extra mal autorizadas.', $auditLog->new_value['reason']);

        $this->assertSame(
            1,
            AuditLog::query()->where('entity_id', $period->id)->where('action', 'payroll_period.reopened')->count(),
        );
    }

    public function test_reopen_with_an_empty_reason_is_rejected()
    {
        $period = $this->period('closed');

        $this->expectException(InvalidArgumentException::class);

        $this->service->reopen($period, $this->actor, '');
    }

    public function test_reopen_with_a_whitespace_only_reason_is_rejected()
    {
        $period = $this->period('closed');

        $this->expectException(InvalidArgumentException::class);

        $this->service->reopen($period, $this->actor, '   ');
    }

    public function test_reopen_from_a_non_closed_status_throws()
    {
        foreach (['open', 'calculated', 'approved', 'reopened'] as $startingStatus) {
            $period = $this->period($startingStatus);

            try {
                $this->service->reopen($period, $this->actor, 'Motivo válido.');
                $this->fail("Expected InvalidPayrollPeriodStatusException when reopening from '{$startingStatus}'.");
            } catch (InvalidPayrollPeriodStatusException) {
                $this->assertTrue(true);
            }
        }
    }

    // ----------------------------------------------------------------
    // close(): deduction plan `remaining` decrement
    // ----------------------------------------------------------------

    public function test_close_decrements_remaining_on_a_deduction_plan_whose_line_was_applied_this_period()
    {
        $period = $this->period('calculated');
        $employee = Employee::factory()->create(['company_id' => $this->company->id]);
        $contract = EmploymentContract::factory()->create([
            'company_id' => $this->company->id,
            'employee_id' => $employee->id,
        ]);

        $plan = PayrollDeductionPlan::factory()->create([
            'company_id' => $this->company->id,
            'employee_id' => $employee->id,
            'total_amount' => 600000,
            'installments' => 6,
            'installment_amount' => 100000,
            'remaining' => 400000,
        ]);

        $entry = PayrollEntry::factory()->create([
            'company_id' => $this->company->id,
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
            'contract_id' => $contract->id,
            'status' => 'calculated',
        ]);

        PayrollEntryLine::factory()->create([
            'company_id' => $this->company->id,
            'payroll_entry_id' => $entry->id,
            'contract_id' => null,
            'deduction_plan_id' => $plan->id,
            'type' => 'deduction',
            'quantity' => null,
            'rate' => null,
            'amount' => 100000,
        ]);

        $this->service->close($period, $this->actor);

        $this->assertEqualsWithDelta(300000.0, (float) $plan->fresh()->remaining, 0.0001);
    }

    public function test_closing_an_already_closed_period_throws_rather_than_double_decrementing_the_plan()
    {
        $period = $this->period('calculated');
        $employee = Employee::factory()->create(['company_id' => $this->company->id]);
        $contract = EmploymentContract::factory()->create([
            'company_id' => $this->company->id,
            'employee_id' => $employee->id,
        ]);

        $plan = PayrollDeductionPlan::factory()->create([
            'company_id' => $this->company->id,
            'employee_id' => $employee->id,
            'total_amount' => 600000,
            'installments' => 6,
            'installment_amount' => 100000,
            'remaining' => 400000,
        ]);

        $entry = PayrollEntry::factory()->create([
            'company_id' => $this->company->id,
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
            'contract_id' => $contract->id,
            'status' => 'calculated',
        ]);

        PayrollEntryLine::factory()->create([
            'company_id' => $this->company->id,
            'payroll_entry_id' => $entry->id,
            'contract_id' => null,
            'deduction_plan_id' => $plan->id,
            'type' => 'deduction',
            'quantity' => null,
            'rate' => null,
            'amount' => 100000,
        ]);

        $this->service->close($period, $this->actor);
        $this->assertEqualsWithDelta(300000.0, (float) $plan->fresh()->remaining, 0.0001);

        try {
            $this->service->close($period->fresh(), $this->actor);
            $this->fail('Expected InvalidPayrollPeriodStatusException on the second close() attempt.');
        } catch (InvalidPayrollPeriodStatusException) {
            // expected
        }

        // Guarded by the status check before any decrement logic runs — the
        // plan's remaining must still reflect only the first, successful
        // close(), never a second consumption of the same line.
        $this->assertEqualsWithDelta(300000.0, (float) $plan->fresh()->remaining, 0.0001);
    }
}
