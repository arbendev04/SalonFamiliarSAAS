<?php

namespace Tests\Feature\Payroll;

use App\Models\AttendanceRecord;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmploymentContract;
use App\Models\LaborRule;
use App\Models\LaborRuleVersion;
use App\Models\PayrollConceptDefinition;
use App\Models\PayrollEntry;
use App\Models\PayrollEntryLine;
use App\Models\PayrollPeriod;
use App\Models\User;
use App\Services\Payroll\PayrollPeriodService;
use App\Services\Tenancy\CurrentCompany;
use Database\Seeders\PayrollConceptCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * End-to-end acceptance scenario for .ai/10-PAYROLL.md's mandated "un
 * contrato que cambia a mitad de periodo" (a contract that changes
 * mid-period) test — the roadmap's own required acceptance criterion #2:
 * "Un contrato partido a mitad de periodo produce múltiples
 * payroll_entry_lines con distinto contract_id dentro de la misma
 * payroll_entry."
 *
 * tests/Unit/Payroll/PayrollCalculationServiceTest.php already proves the
 * ALGORITHM correct in isolation
 * (resolveContractSubRanges()/resolveDailyRate()/proratedBaseSalaryLines()
 * via an anonymous-subclass wrapper around protected methods) — including
 * the exact hand-worked March 2025 split-contract example this test reuses
 * verbatim (period Mar 1-15, contract A 3,100,000 -> 8 days -> 800,000,
 * contract B 4,650,000 -> 7 days -> 1,050,000). This test instead drives the
 * REAL public entry point (PayrollPeriodService::calculate(), which
 * delegates to PayrollCalculationService::calculateForEmployee()/
 * calculateForPeriod()) from real employment_contracts fixtures through to
 * a persisted PayrollEntry + payroll_entry_lines, proving the full stack —
 * not just the isolated computational helpers — produces the correct
 * multiple lines with distinct contract_id.
 */
class MidPeriodContractChangePayrollTest extends TestCase
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

        // Same 15-day period the unit test's split-contract example uses:
        // March 2025 has 31 calendar days, so neither sub-range's daily
        // rate is a repeating decimal (3,100,000/31 = 100,000 exactly;
        // 4,650,000/31 = 150,000 exactly) — chosen deliberately for
        // hand-verifiable pesos, per
        // tests/Unit/Payroll/PayrollCalculationServiceTest.php::
        // test_prorated_base_salary_lines_for_a_split_contract_produces_two_lines_with_the_correct_money_math().
        $this->period = PayrollPeriod::factory()->create([
            'company_id' => $this->company->id,
            'period_type' => 'biweekly',
            'start_date' => '2025-03-01',
            'end_date' => '2025-03-15',
            'status' => 'open',
        ]);

        // A valid STANDARD_WORKWEEK labor_rule_version covering the period.
        // Not actually consulted by this scenario (there is no authorized
        // overtime here, and authorizedOvertimeLines() only resolves a
        // labor_rule_version when an authorized OvertimeRecord exists), but
        // included for realism/consistency with the sibling biweekly
        // integration test's fixture conventions.
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
     * A realistic (not exhaustive) spread of attendance across the period —
     * enough to satisfy
     * PayrollCalculationService::assertHasAttendanceOrNoveltyCoverage(),
     * which only requires at least one AttendanceRecord somewhere inside
     * [period.start_date, period.end_date]. Attendance data has no bearing
     * on the base-salary money math (prorated purely from the
     * contract/salary history, per plan section G) — this only exists to
     * clear the coverage guard the way real timekeeping data would.
     */
    private function seedAttendance(Employee $employee): void
    {
        foreach (['2025-03-01', '2025-03-02', '2025-03-03', '2025-03-06', '2025-03-07', '2025-03-08', '2025-03-09', '2025-03-10', '2025-03-13', '2025-03-14'] as $date) {
            AttendanceRecord::factory()->create([
                'company_id' => $this->company->id,
                'employee_id' => $employee->id,
                'date' => $date,
            ]);
        }
    }

    /**
     * PayrollConceptDefinition rows are platform defaults (company_id null)
     * seeded by PayrollConceptCatalogSeeder. With a tenant active,
     * BelongsToCompany's global scope would silently exclude them from a
     * bare query, so this resolves via effectiveForCompany() — the same
     * bypass PayrollCalculationService::resolveConceptId() itself is
     * required to use.
     */
    private function conceptIdByCode(string $code): string
    {
        return PayrollConceptDefinition::query()
            ->effectiveForCompany($this->company->id)
            ->where('code', $code)
            ->firstOrFail()
            ->id;
    }

    public function test_a_contract_that_changes_mid_period_produces_two_payroll_entry_lines_with_distinct_contract_ids_and_correct_prorated_amounts()
    {
        // ------------------------------------------------------------
        // Fixture: one employee, promoted mid-period.
        // ------------------------------------------------------------

        $employee = Employee::factory()->create(['company_id' => $this->company->id]);

        // Contract A: in force before the period starts, ends day 8 of the
        // 15-day period (the day before the promotion takes effect).
        // Daily rate = 3,100,000 / 31 days in March = 100,000/day.
        // Sub-range Mar 1-8 = 8 calendar days -> 100,000 * 8 = 800,000.
        $contractA = EmploymentContract::factory()->create([
            'company_id' => $this->company->id,
            'employee_id' => $employee->id,
            'start_date' => '2025-01-01',
            'end_date' => '2025-03-08',
            'base_salary' => 3100000,
        ]);

        // Contract B: a genuine raise (promotion) effective the very next
        // day, continuing past the period end.
        // Daily rate = 4,650,000 / 31 days in March = 150,000/day.
        // Sub-range Mar 9-15 = 7 calendar days -> 150,000 * 7 = 1,050,000.
        $contractB = EmploymentContract::factory()->create([
            'company_id' => $this->company->id,
            'employee_id' => $employee->id,
            'start_date' => '2025-03-09',
            'end_date' => null,
            'base_salary' => 4650000,
        ]);

        $this->seedAttendance($employee);

        // ------------------------------------------------------------
        // calculate(): open -> calculated.
        // ------------------------------------------------------------

        $calculated = app(PayrollPeriodService::class)->calculate($this->period, $this->actor);

        $this->assertSame('calculated', $calculated->status);

        $entry = PayrollEntry::query()
            ->where('payroll_period_id', $this->period->id)
            ->where('employee_id', $employee->id)
            ->firstOrFail();

        $this->assertSame('calculated', $entry->status);

        // payroll_entries.contract_id is the summary pointer to the LAST
        // sub-range's contract (plan section G, step 8: "puntero de
        // resumen, no fuente de verdad") — confirmed here against the real
        // commit-10 implementation rather than assumed: contract B, the
        // promoted contract, since it covers the tail of the period.
        $this->assertSame($contractB->id, $entry->contract_id);

        // ------------------------------------------------------------
        // Exactly two BASE_SALARY lines, one per contract, by contract id —
        // the literal acceptance criterion.
        // ------------------------------------------------------------

        $baseSalaryConceptId = $this->conceptIdByCode('BASE_SALARY');

        $baseSalaryLines = $entry->lines()
            ->where('concept_id', $baseSalaryConceptId)
            ->get();

        $this->assertCount(2, $baseSalaryLines);

        $lineA = $baseSalaryLines->firstWhere('contract_id', $contractA->id);
        $lineB = $baseSalaryLines->firstWhere('contract_id', $contractB->id);

        $this->assertNotNull($lineA);
        $this->assertNotNull($lineB);
        $this->assertTrue($baseSalaryLines->every(fn (PayrollEntryLine $line): bool => $line->type === 'earning'));

        // Hand-computed expected numbers (same math as the unit test's
        // split-contract example, reused verbatim):
        //   Contract A: rate 100,000/day * 8 days = 800,000.
        //   Contract B: rate 150,000/day * 7 days = 1,050,000.
        $this->assertEqualsWithDelta(8.0, (float) $lineA->quantity, 0.0001);
        $this->assertEqualsWithDelta(100000.0, (float) $lineA->rate, 0.0001);
        $this->assertEqualsWithDelta(800000.0, (float) $lineA->amount, 0.01);

        $this->assertEqualsWithDelta(7.0, (float) $lineB->quantity, 0.0001);
        $this->assertEqualsWithDelta(150000.0, (float) $lineB->rate, 0.0001);
        $this->assertEqualsWithDelta(1050000.0, (float) $lineB->amount, 0.01);

        // Tiling held at the full-stack level too: the two sub-ranges' day
        // counts sum to exactly the period's total day count (Mar 1-15
        // inclusive = 15 days), no gap, no overlap.
        $totalDays = (float) $lineA->quantity + (float) $lineB->quantity;
        $this->assertEqualsWithDelta(15.0, $totalDays, 0.0001);
        $this->assertEqualsWithDelta(15.0, $this->period->start_date->diffInDays($this->period->end_date) + 1, 0.0001);

        // ------------------------------------------------------------
        // gross_total/net_total equal the sum of both lines' amounts.
        // ------------------------------------------------------------

        $expectedTotal = 800000.0 + 1050000.0;
        $this->assertEqualsWithDelta($expectedTotal, (float) $entry->gross_total, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $entry->deductions_total, 0.0001);
        $this->assertEqualsWithDelta($expectedTotal, (float) $entry->net_total, 0.01);
    }

    /**
     * The error side of the same code path: a GAP between two contracts
     * (days 6-9 of the period have no contract at all) blocks the
     * calculation of THAT employee only — matching the roadmap's
     * "contrato ambiguo... no bloquea el cálculo del resto" acceptance
     * criterion. A second, unaffected employee in the same batch proves
     * per-employee isolation concretely: their entry calculates normally
     * despite the first employee's gap.
     */
    public function test_a_gap_between_two_contracts_mid_period_blocks_only_that_employee_and_does_not_affect_the_rest_of_the_batch()
    {
        // Employee with the gap: contract ends day 5, next contract starts
        // day 10 — days 6-9 (Mar 6-9) have no contract at all.
        $gappedEmployee = Employee::factory()->create(['company_id' => $this->company->id]);
        EmploymentContract::factory()->create([
            'company_id' => $this->company->id,
            'employee_id' => $gappedEmployee->id,
            'start_date' => '2025-01-01',
            'end_date' => '2025-03-05',
            'base_salary' => 3000000,
        ]);
        EmploymentContract::factory()->create([
            'company_id' => $this->company->id,
            'employee_id' => $gappedEmployee->id,
            'start_date' => '2025-03-10',
            'end_date' => null,
            'base_salary' => 3000000,
        ]);
        $this->seedAttendance($gappedEmployee);

        // Second, unaffected employee with a single contract spanning the
        // whole period.
        $normalEmployee = Employee::factory()->create(['company_id' => $this->company->id]);
        EmploymentContract::factory()->create([
            'company_id' => $this->company->id,
            'employee_id' => $normalEmployee->id,
            'start_date' => '2025-01-01',
            'end_date' => null,
            'base_salary' => 3100000,
        ]);
        $this->seedAttendance($normalEmployee);

        $calculated = app(PayrollPeriodService::class)->calculate($this->period, $this->actor);

        // calculate() itself completes successfully — a per-employee block
        // never aborts the whole batch/period-level transition.
        $this->assertSame('calculated', $calculated->status);

        $gappedEntry = PayrollEntry::query()
            ->where('payroll_period_id', $this->period->id)
            ->where('employee_id', $gappedEmployee->id)
            ->firstOrFail();

        $this->assertSame('blocked', $gappedEntry->status);
        $this->assertNull($gappedEntry->contract_id);
        $this->assertEqualsWithDelta(0.0, (float) $gappedEntry->gross_total, 0.0001);
        $this->assertEqualsWithDelta(0.0, (float) $gappedEntry->deductions_total, 0.0001);
        $this->assertEqualsWithDelta(0.0, (float) $gappedEntry->net_total, 0.0001);
        $this->assertCount(0, $gappedEntry->lines()->get());

        $normalEntry = PayrollEntry::query()
            ->where('payroll_period_id', $this->period->id)
            ->where('employee_id', $normalEmployee->id)
            ->firstOrFail();

        // Daily rate = 3,100,000 / 31 days in March = 100,000/day * 15 days
        // (Mar 1-15) = 1,500,000.
        $this->assertSame('calculated', $normalEntry->status);
        $this->assertEqualsWithDelta(1500000.0, (float) $normalEntry->gross_total, 0.01);
        $this->assertEqualsWithDelta(1500000.0, (float) $normalEntry->net_total, 0.01);
        $this->assertCount(1, $normalEntry->lines()->get());
    }
}
