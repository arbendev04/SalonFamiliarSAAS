<?php

namespace Tests\Feature\Payroll;

use App\Exceptions\InvalidPayrollPeriodStatusException;
use App\Models\AttendanceRecord;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmploymentContract;
use App\Models\LaborRule;
use App\Models\LaborRuleVersion;
use App\Models\OvertimeRecord;
use App\Models\PayrollConceptDefinition;
use App\Models\PayrollDeductionPlan;
use App\Models\PayrollEntry;
use App\Models\PayrollEntryLine;
use App\Models\PayrollPeriod;
use App\Models\Shift;
use App\Models\User;
use App\Services\Payroll\PayrollCalculationService;
use App\Services\Payroll\PayrollPeriodService;
use App\Services\Tenancy\CurrentCompany;
use Database\Seeders\PayrollConceptCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * End-to-end acceptance scenario for .ai/10-PAYROLL.md's mandated "quincena
 * completa" (full biweekly period) test — the roadmap's own required
 * acceptance criterion, not a re-run of commits 6-12's unit coverage
 * (tests/Unit/Payroll/*), which already exercises every computational
 * helper and state transition in isolation via an anonymous-subclass
 * wrapper. This test drives the REAL public API
 * (PayrollPeriodService::calculate()/close()) across three employees with
 * genuinely different shapes — plain salary, authorized overtime, and an
 * active deduction plan — and hand-verifies the resulting money, proving
 * the whole pipeline actually produces the right numbers together, not
 * just that each piece is individually correct.
 *
 * Per ADR-008 (.ai/23-DECISIONS.md): payroll_periods.period_type is a plain
 * configuration value, never a hardcoded special case in the calculation
 * engine. This test uses period_type='biweekly' explicitly and the period
 * is handled by exactly the same generic date-range logic
 * (resolveContractSubRanges()/proratedBaseSalaryLines()) that any other
 * period_type would go through — nothing in PayrollCalculationService
 * branches on period_type at all, which this test's passing is itself
 * partial evidence of.
 */
class BiweeklyPayrollCalculationTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $actor;

    private PayrollPeriod $period;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PayrollConceptCatalogSeeder::class);

        $this->company = Company::factory()->create();
        app(CurrentCompany::class)->set($this->company);

        $this->actor = User::factory()->create();

        // A real 15-day biweekly range, April 1-15 (April has 30 calendar
        // days, so a 15-day sub-range is exactly half the month — chosen
        // deliberately so the daily-rate math below comes out to clean,
        // hand-verifiable pesos rather than a repeating decimal).
        $this->period = PayrollPeriod::factory()->create([
            'company_id' => $this->company->id,
            'period_type' => 'biweekly',
            'start_date' => '2026-04-01',
            'end_date' => '2026-04-15',
            'status' => 'open',
        ]);

        // One STANDARD_WORKWEEK labor_rule_version covering the whole
        // period, with both the time-side parameters (tolerance_minutes/
        // rounding_minutes, pre-existing from Fase 7) and the two
        // money-side keys Fase 9 added (monthly_hours_divisor/
        // overtime_surcharge_pct) — required by
        // PayrollCalculationService::authorizedOvertimeLines() for Employee
        // B's overtime line.
        $laborRule = LaborRule::factory()->create([
            'company_id' => $this->company->id,
            'rule_type' => 'STANDARD_WORKWEEK',
        ]);
        LaborRuleVersion::factory()->create([
            'company_id' => $this->company->id,
            'labor_rule_id' => $laborRule->id,
            'effective_from' => '2025-01-01',
            'effective_to' => null,
            'parameters' => [
                'tolerance_minutes' => 15,
                'rounding_minutes' => 5,
                'monthly_hours_divisor' => 240,
                'overtime_surcharge_pct' => 0.25,
            ],
        ]);
    }

    /**
     * A realistic (not exhaustive) spread of attendance for one employee
     * across the period — enough to satisfy
     * PayrollCalculationService::assertHasAttendanceOrNoveltyCoverage(),
     * which only requires at least one AttendanceRecord somewhere inside
     * [period.start_date, period.end_date]; it does not gate on every
     * single day being covered. Attendance data has no bearing on the
     * money math here (base salary is prorated purely from the contract/
     * salary history, per plan section G) — this only exists to clear the
     * coverage guard the way real timekeeping data would.
     */
    private function seedAttendance(Employee $employee): void
    {
        foreach (['2026-04-01', '2026-04-02', '2026-04-03', '2026-04-06', '2026-04-07', '2026-04-08', '2026-04-09', '2026-04-10', '2026-04-13', '2026-04-14'] as $date) {
            AttendanceRecord::factory()->create([
                'company_id' => $this->company->id,
                'employee_id' => $employee->id,
                'date' => $date,
            ]);
        }
    }

    /**
     * PayrollConceptDefinition rows are platform defaults (company_id
     * null) seeded by PayrollConceptCatalogSeeder. With a tenant active
     * (this test's setUp() calls CurrentCompany::set()), BelongsToCompany's
     * global scope filters every plain query — including the implicit one
     * behind PayrollEntryLine::concept()'s BelongsTo relation — down to
     * company_id = current company, which silently excludes these null-
     * company rows. Resolving via effectiveForCompany() sidesteps that
     * scope, the same way PayrollCalculationService::resolveConceptId()
     * itself is required to (see that method's docblock for the exact Fase
     * 8 regression this mirrors).
     */
    private function conceptIdByCode(string $code): string
    {
        return PayrollConceptDefinition::query()
            ->effectiveForCompany($this->company->id)
            ->where('code', $code)
            ->firstOrFail()
            ->id;
    }

    public function test_a_full_biweekly_period_with_three_employees_calculates_and_closes_with_the_expected_money_math()
    {
        // ------------------------------------------------------------
        // Fixture: three employees, three different shapes.
        // ------------------------------------------------------------

        // Employee A: plain salary, no overtime, no deductions.
        // Daily rate = 3,000,000 / 30 days in April = 100,000/day.
        // 15 days (Apr 1-15 inclusive) * 100,000 = 1,500,000.
        $employeeA = Employee::factory()->create(['company_id' => $this->company->id]);
        EmploymentContract::factory()->create([
            'company_id' => $this->company->id,
            'employee_id' => $employeeA->id,
            'start_date' => '2025-01-01',
            'end_date' => null,
            'base_salary' => 3000000,
        ]);
        $this->seedAttendance($employeeA);

        // Employee B: has one authorized overtime record.
        // Base: daily rate = 2,400,000 / 30 = 80,000/day * 15 days = 1,200,000.
        // Overtime: hourly_rate = 2,400,000 / 240 = 10,000;
        //           overtime_rate = 10,000 * 1.25 = 12,500;
        //           authorized_minutes = 120 -> 2.0 hours;
        //           amount = 12,500 * 2.0 = 25,000.
        // Gross = 1,200,000 + 25,000 = 1,225,000.
        $employeeB = Employee::factory()->create(['company_id' => $this->company->id]);
        EmploymentContract::factory()->create([
            'company_id' => $this->company->id,
            'employee_id' => $employeeB->id,
            'start_date' => '2025-01-01',
            'end_date' => null,
            'base_salary' => 2400000,
        ]);
        $this->seedAttendance($employeeB);
        $shift = Shift::factory()->create([
            'company_id' => $this->company->id,
            'date' => '2026-04-08',
        ]);
        OvertimeRecord::factory()->create([
            'company_id' => $this->company->id,
            'employee_id' => $employeeB->id,
            'shift_id' => $shift->id,
            'detected_minutes' => 120,
            'authorized_minutes' => 120,
            'status' => 'authorized',
        ]);

        // Employee C: has one active deduction plan.
        // Base: daily rate = 2,700,000 / 30 = 90,000/day * 15 days = 1,350,000.
        // Deduction: min(installment_amount=200,000, remaining=800,000) = 200,000.
        // Net = 1,350,000 - 200,000 = 1,150,000.
        $employeeC = Employee::factory()->create(['company_id' => $this->company->id]);
        EmploymentContract::factory()->create([
            'company_id' => $this->company->id,
            'employee_id' => $employeeC->id,
            'start_date' => '2025-01-01',
            'end_date' => null,
            'base_salary' => 2700000,
        ]);
        $this->seedAttendance($employeeC);
        $plan = PayrollDeductionPlan::factory()->create([
            'company_id' => $this->company->id,
            'employee_id' => $employeeC->id,
            'total_amount' => 1000000,
            'installments' => 5,
            'installment_amount' => 200000,
            'remaining' => 800000,
        ]);

        // ------------------------------------------------------------
        // calculate(): open -> calculated.
        // ------------------------------------------------------------

        $calculated = app(PayrollPeriodService::class)->calculate($this->period, $this->actor);

        $this->assertSame('calculated', $calculated->status);
        $this->assertSame('calculated', $this->period->fresh()->status);

        $entries = PayrollEntry::query()->where('payroll_period_id', $this->period->id)->get();
        $this->assertCount(3, $entries);
        $this->assertTrue($entries->every(fn (PayrollEntry $entry): bool => $entry->status === 'calculated'));

        $entryA = $entries->firstWhere('employee_id', $employeeA->id);
        $entryB = $entries->firstWhere('employee_id', $employeeB->id);
        $entryC = $entries->firstWhere('employee_id', $employeeC->id);

        $this->assertNotNull($entryA);
        $this->assertNotNull($entryB);
        $this->assertNotNull($entryC);

        // Employee A: plain salary only.
        $this->assertEqualsWithDelta(1500000.0, (float) $entryA->gross_total, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $entryA->deductions_total, 0.0001);
        $this->assertEqualsWithDelta(1500000.0, (float) $entryA->net_total, 0.01);
        $this->assertCount(1, $entryA->lines()->get());

        // Employee B: base salary + overtime earning line.
        $this->assertEqualsWithDelta(1225000.0, (float) $entryB->gross_total, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $entryB->deductions_total, 0.0001);
        $this->assertEqualsWithDelta(1225000.0, (float) $entryB->net_total, 0.01);

        $entryBLines = $entryB->lines()->get();
        $this->assertCount(2, $entryBLines);
        $this->assertTrue($entryBLines->every(fn (PayrollEntryLine $line): bool => $line->type === 'earning'));
        $overtimeConceptId = $this->conceptIdByCode('OVERTIME');
        $overtimeLine = $entryBLines->firstWhere('concept_id', $overtimeConceptId);
        $this->assertNotNull($overtimeLine);
        $this->assertEqualsWithDelta(25000.0, (float) $overtimeLine->amount, 0.01);
        $this->assertEqualsWithDelta(2.0, (float) $overtimeLine->quantity, 0.0001);

        // Employee C: base salary minus one deduction line.
        $this->assertEqualsWithDelta(1350000.0, (float) $entryC->gross_total, 0.01);
        $this->assertEqualsWithDelta(200000.0, (float) $entryC->deductions_total, 0.01);
        $this->assertEqualsWithDelta(1150000.0, (float) $entryC->net_total, 0.01);

        $deductionLine = $entryC->lines()->where('type', 'deduction')->first();
        $this->assertNotNull($deductionLine);
        $this->assertEqualsWithDelta(200000.0, (float) $deductionLine->amount, 0.01);
        $this->assertSame($plan->id, $deductionLine->deduction_plan_id);

        // Deduction plan is NOT decremented yet — that only happens at
        // close(), never during calculate()/recalculation.
        $this->assertEqualsWithDelta(800000.0, (float) $plan->fresh()->remaining, 0.0001);

        // Exactly one audit row for the calculate() transition.
        $this->assertSame(
            1,
            AuditLog::query()->where('entity_id', $this->period->id)->where('action', 'payroll_period.calculated')->count(),
        );

        // ------------------------------------------------------------
        // close(): calculated -> closed.
        // ------------------------------------------------------------

        $closed = app(PayrollPeriodService::class)->close($this->period, $this->actor);

        $this->assertSame('closed', $closed->status);
        $this->assertSame($this->actor->id, $closed->closed_by);
        $this->assertNotNull($closed->closed_at);

        $freshPeriod = $this->period->fresh();
        $this->assertSame('closed', $freshPeriod->status);
        $this->assertSame($this->actor->id, $freshPeriod->closed_by);
        $this->assertNotNull($freshPeriod->closed_at);

        // Exactly one audit row for calculate() and exactly one for close()
        // across the whole flow — never more, never fewer.
        $this->assertSame(
            1,
            AuditLog::query()->where('entity_id', $this->period->id)->where('action', 'payroll_period.calculated')->count(),
        );
        $this->assertSame(
            1,
            AuditLog::query()->where('entity_id', $this->period->id)->where('action', 'payroll_period.closed')->count(),
        );
        $this->assertSame(
            2,
            AuditLog::query()->where('entity_id', $this->period->id)->count(),
        );

        // Employee C's deduction plan is decremented exactly once, only now.
        $this->assertEqualsWithDelta(600000.0, (float) $plan->fresh()->remaining, 0.0001);

        // Money on all three entries is unchanged by close() itself — close()
        // only flips the period's own status and decrements deduction plans,
        // it never recalculates or rewrites any entry/line.
        $this->assertEqualsWithDelta(1500000.0, (float) $entryA->fresh()->gross_total, 0.01);
        $this->assertEqualsWithDelta(1225000.0, (float) $entryB->fresh()->gross_total, 0.01);
        $this->assertEqualsWithDelta(1350000.0, (float) $entryC->fresh()->gross_total, 0.01);

        // ------------------------------------------------------------
        // Post-close immutability discipline.
        // ------------------------------------------------------------

        // payroll_entries/payroll_entry_lines carry NO ORM-level
        // immutability guard (plan's explicit design, matching
        // AttendanceAdjustment's precedent) — a raw Eloquent update is NOT
        // technically blocked at this layer. Confirming that here is part
        // of the contract, not an oversight: the guard belongs exclusively
        // to the service layer.
        $entryA->update(['net_total' => 999999]);
        $this->assertEqualsWithDelta(999999.0, (float) $entryA->fresh()->net_total, 0.01);
        // Restore it so the rest of this test's assertions (and the
        // service-level guard check below) reason about the real,
        // service-computed value rather than this deliberately-corrupted one.
        $entryA->update(['net_total' => 1500000]);

        // The SERVICE itself, however, refuses to recalculate a closed
        // period: PayrollPeriodService::calculate() rejects 'closed' with
        // InvalidPayrollPeriodStatusException before ever touching
        // PayrollCalculationService — exercised here directly (not merely
        // assumed from the unit tests) against the real closed period this
        // test just produced.
        $this->expectException(InvalidPayrollPeriodStatusException::class);
        app(PayrollPeriodService::class)->calculate($this->period->fresh(), $this->actor);
    }

    public function test_calculate_for_employee_directly_against_a_closed_period_throws_and_does_not_corrupt_the_existing_entry()
    {
        // A separate, narrower assertion that
        // PayrollCalculationService::calculateForEmployee() itself — not
        // just PayrollPeriodService's status guard — is exercised against a
        // genuinely closed period, per the task's requirement to confirm
        // the guard is actually exercised rather than assumed.
        $employee = Employee::factory()->create(['company_id' => $this->company->id]);
        EmploymentContract::factory()->create([
            'company_id' => $this->company->id,
            'employee_id' => $employee->id,
            'start_date' => '2025-01-01',
            'end_date' => null,
            'base_salary' => 3000000,
        ]);
        $this->seedAttendance($employee);

        app(PayrollPeriodService::class)->calculate($this->period, $this->actor);
        $closedPeriod = app(PayrollPeriodService::class)->close($this->period->fresh(), $this->actor);

        $entryBeforeAttempt = PayrollEntry::query()
            ->where('payroll_period_id', $closedPeriod->id)
            ->where('employee_id', $employee->id)
            ->firstOrFail();
        $entryAttributesBeforeAttempt = $entryBeforeAttempt->fresh()->toArray();
        $linesBeforeAttempt = $entryBeforeAttempt->lines()->orderBy('id')->get()->toArray();

        // PayrollCalculationService::calculateForEmployee() now guards
        // period status itself, in addition to PayrollPeriodService's own
        // guard — defense in depth for the phase's core acceptance
        // criterion that a closed period is immutable at the application
        // level no matter which code path reaches the write (a future
        // scheduled job or console command calling this service directly,
        // bypassing PayrollPeriodService entirely, must be blocked just the
        // same). Calling it directly against a genuinely closed period
        // therefore throws immediately and leaves the existing entry/lines
        // completely untouched, confirmed here rather than assumed.
        try {
            app(PayrollCalculationService::class)->calculateForEmployee($closedPeriod->fresh(), $employee);
            $this->fail('Expected InvalidPayrollPeriodStatusException to be thrown.');
        } catch (InvalidPayrollPeriodStatusException $exception) {
            // expected
        }

        $this->assertSame($entryAttributesBeforeAttempt, $entryBeforeAttempt->fresh()->toArray());
        $this->assertSame($linesBeforeAttempt, $entryBeforeAttempt->lines()->orderBy('id')->get()->toArray());
    }
}
