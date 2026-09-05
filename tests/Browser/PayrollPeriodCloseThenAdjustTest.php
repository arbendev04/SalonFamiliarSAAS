<?php

namespace Tests\Browser;

use App\Models\AttendanceRecord;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmploymentContract;
use App\Models\LaborRule;
use App\Models\LaborRuleVersion;
use App\Models\PayrollAdjustment;
use App\Models\PayrollConceptDefinition;
use App\Models\PayrollEntry;
use App\Models\PayrollPeriod;
use App\Models\Role;
use App\Models\User;
use App\Models\UserCompanyMembership;
use Database\Seeders\PayrollConceptCatalogSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * Flow C from .ai/21-TESTING.md: a real Chrome browser drives the full
 * ADR-026 "default" correction mechanism — closing a payroll period and
 * then correcting it via a payroll_adjustments row that lands in the NEXT
 * period, never by mutating the closed one — as a single SUPER_ADMIN user
 * (no other role holds payroll.calculate + payroll.close + payroll.adjust
 * together, and role boundaries are already exhaustively covered by 102
 * RBAC assertions elsewhere; this test proves the flow, not the roles).
 *
 * Fixture mirrors BiweeklyPayrollCalculationTest::
 * test_a_full_biweekly_period_with_three_employees_calculates_and_closes_with_the_expected_money_math()
 * exactly (single employee, plain-salary shape): base_salary 3,000,000 over
 * a 30-day April, daily rate 100,000, so both 15-day biweekly periods below
 * (Apr 1-15 and Apr 16-30) settle to a clean, hand-verifiable gross_total
 * of 1,500,000 with no overtime and no deductions to reason about.
 *
 * PayrollAdjustmentService::adjustInNextPeriod()'s docblock documents the
 * required sequencing this test exercises: the target (next) period must
 * already have an existing, CALCULATED PayrollEntry for the employee before
 * the "Solicitar ajuste" request against the closed period will succeed —
 * skipping that step throws NoOpenNextPayrollPeriodException with no
 * try/catch in PayrollAdjustmentController::store(), falling through to a
 * raw 500 (see that controller's own docblock).
 */
class PayrollPeriodCloseThenAdjustTest extends DuskTestCase
{
    use DatabaseTruncation;

    public function test_a_closed_period_is_corrected_via_an_adjustment_in_the_next_period(): void
    {
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        $this->seed(PayrollConceptCatalogSeeder::class);

        $company = Company::factory()->create();

        $employee = Employee::factory()->create(['company_id' => $company->id]);
        EmploymentContract::factory()->create([
            'company_id' => $company->id,
            'employee_id' => $employee->id,
            'start_date' => '2025-01-01',
            'end_date' => null,
            'base_salary' => 3000000,
        ]);

        $laborRule = LaborRule::factory()->create([
            'company_id' => $company->id,
            'rule_type' => 'STANDARD_WORKWEEK',
        ]);
        LaborRuleVersion::factory()->create([
            'company_id' => $company->id,
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

        // assertHasAttendanceOrNoveltyCoverage() only requires at least one
        // AttendanceRecord inside each period's date range — money math is
        // driven purely by the contract's base_salary (see
        // BiweeklyPayrollCalculationTest::seedAttendance()'s own docblock).
        foreach (['2026-04-02', '2026-04-08', '2026-04-14'] as $date) {
            AttendanceRecord::factory()->create([
                'company_id' => $company->id,
                'employee_id' => $employee->id,
                'date' => $date,
            ]);
        }
        foreach (['2026-04-17', '2026-04-23', '2026-04-29'] as $date) {
            AttendanceRecord::factory()->create([
                'company_id' => $company->id,
                'employee_id' => $employee->id,
                'date' => $date,
            ]);
        }

        $role = Role::query()->whereNull('company_id')->where('name', 'SUPER_ADMIN')->firstOrFail();
        $user = User::factory()->create();
        UserCompanyMembership::create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'role_id' => $role->id,
            'status' => 'active',
        ]);

        $loanConcept = PayrollConceptDefinition::query()
            ->effectiveForCompany($company->id)
            ->where('code', 'LOAN')
            ->firstOrFail();

        $adjustmentReason = 'Corrección retroactiva de un valor mal calculado en el periodo anterior.';

        $entrySnapshot = null;
        $lineSnapshot = null;

        $this->browse(function (Browser $browser) use ($user, $company, $loanConcept, $adjustmentReason, &$entrySnapshot, &$lineSnapshot) {
            $browser->loginAs($user);

            $this->createPeriod($browser, '2026-04-01', '2026-04-15');
            $period1 = PayrollPeriod::query()
                ->where('company_id', $company->id)
                ->where('start_date', '2026-04-01')
                ->firstOrFail();

            // Calcular -> Aprobar -> Cerrar. close()'s own precondition
            // (calculated|approved|reopened) means "Aprobar" is optional
            // per ADR-034 — this test still exercises it because the plan
            // asks for the full three-button sequence.
            $browser->visit("/payroll/periods/{$period1->id}")
                ->press('Calcular')
                ->waitForText('Calculado', 10)
                ->press('Aprobar')
                ->waitForText('Aprobado', 10)
                ->press('Cerrar')
                ->waitForText('Cerrado', 10);

            // Snapshot Period 1's entry/lines right after Cerrar succeeds —
            // this is the "known good, just closed" state the final
            // byte-for-byte immutability assertion compares against.
            $entry1 = PayrollEntry::query()
                ->where('payroll_period_id', $period1->id)
                ->firstOrFail();
            $entrySnapshot = $entry1->fresh()->toArray();
            $lineSnapshot = $entry1->lines()->orderBy('id')->get()->toArray();

            // Period 2: the immediately-following biweekly period, zero
            // gap (starts the day after Period 1 ends). Calculate it and
            // leave it 'calculated' — adjustInNextPeriod() only needs an
            // existing, calculated PayrollEntry to attach the correction
            // line to, not a closed period.
            $this->createPeriod($browser, '2026-04-16', '2026-04-30');
            $period2 = PayrollPeriod::query()
                ->where('company_id', $company->id)
                ->where('start_date', '2026-04-16')
                ->firstOrFail();

            $browser->visit("/payroll/periods/{$period2->id}")
                ->press('Calcular')
                ->waitForText('Calculado', 10);

            // Back to Period 1 — now 'closed', so "Solicitar ajuste"
            // becomes visible. Fields have no id attributes (only name),
            // and "reason" collides with the "Reabrir" form's own reason
            // field elsewhere on the page, so every selector is scoped to
            // inside the entries table where the adjustment form lives.
            $browser->visit("/payroll/periods/{$period1->id}")
                ->waitForText('Solicitar ajuste', 10)
                ->select('table [name="concept_id"]', $loanConcept->id)
                ->type('table [name="amount"]', '50000')
                ->select('table [name="type"]', 'deduction');

            $browser->type('table [name="reason"]', $adjustmentReason)
                ->press('Solicitar ajuste')
                ->waitForText('Ajuste registrado en el próximo periodo.', 10);
        });

        $period1 = PayrollPeriod::query()->where('company_id', $company->id)->where('start_date', '2026-04-01')->firstOrFail();
        $period2 = PayrollPeriod::query()->where('company_id', $company->id)->where('start_date', '2026-04-16')->firstOrFail();

        $entry1 = PayrollEntry::query()->where('payroll_period_id', $period1->id)->firstOrFail();
        $entry2 = PayrollEntry::query()->where('payroll_period_id', $period2->id)->firstOrFail();

        // The PayrollAdjustment references Period 2's entry via
        // applied_in_period_id — NOT Period 1's, which is the entire point
        // of adjustInNextPeriod() (ADR-026: a closed entry is never
        // rewritten, the correction always lands on the following period).
        $adjustment = PayrollAdjustment::query()
            ->where('payroll_entry_id', $entry1->id)
            ->firstOrFail();

        $this->assertSame('next_period', $adjustment->mechanism);
        $this->assertSame($period2->id, $adjustment->applied_in_period_id);
        $this->assertSame($loanConcept->id, $adjustment->corrected_value['concept_id']);
        $this->assertEqualsWithDelta(50000.0, (float) $adjustment->corrected_value['amount'], 0.01);
        $this->assertSame('deduction', $adjustment->corrected_value['type']);
        $this->assertSame($adjustmentReason, $adjustment->reason);
        $this->assertSame($user->id, $adjustment->created_by);
        $this->assertNull($adjustment->original_value);

        // Period 2's entry actually received the compensating line: gross
        // stays at 1,500,000 (the adjustment is a deduction, not an
        // earning), deductions_total gains exactly the 50,000 adjustment,
        // net_total reflects it.
        $entry2Fresh = $entry2->fresh();
        $this->assertEqualsWithDelta(1500000.0, (float) $entry2Fresh->gross_total, 0.01);
        $this->assertEqualsWithDelta(50000.0, (float) $entry2Fresh->deductions_total, 0.01);
        $this->assertEqualsWithDelta(1450000.0, (float) $entry2Fresh->net_total, 0.01);

        // Period 1's original entry/lines are byte-for-byte unchanged from
        // their state right after close() — closed-period immutability per
        // .ai/21-TESTING.md's "Casos especiales" and ADR-026.
        $this->assertSame($entrySnapshot, $entry1->fresh()->toArray());
        $this->assertSame($lineSnapshot, $entry1->lines()->orderBy('id')->get()->toArray());
    }

    private function createPeriod(Browser $browser, string $startDate, string $endDate): void
    {
        $browser->visit('/payroll/periods')
            ->select('#period_type', 'biweekly');

        // Browser::script() always returns an array of results (even for a
        // single script string), so it cannot be chained — see Dusk source
        // (already discovered in Flow A/B's commits). <input type="date">
        // fields get the same direct-value treatment as Flow A/B's
        // datetime-local fields to avoid locale-dependent keystroke typing.
        $browser->script([
            "document.getElementById('start_date').value = '{$startDate}'",
            "document.getElementById('end_date').value = '{$endDate}'",
        ]);

        $browser->press('Crear periodo')
            ->waitForText($startDate, 10);
    }
}
