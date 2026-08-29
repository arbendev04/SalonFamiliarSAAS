<?php

namespace Tests\Feature\Payroll;

use App\Exceptions\InvalidPayrollPeriodStatusException;
use App\Models\AttendanceRecord;
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
use App\Services\Payroll\PayrollPeriodService;
use App\Services\Tenancy\CurrentCompany;
use Database\Seeders\PayrollConceptCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * End-to-end acceptance scenario for .ai/10-PAYROLL.md's mandated "un cierre
 * seguido de una corrección posterior" (a close followed by a later
 * correction) test — the roadmap's own required acceptance criterion #3,
 * directly quoting ADR-026: "el cierre de un periodo es efectivamente
 * inmutable; un ajuste posterior al cierre no sobrescribe la entrada
 * original."
 *
 * tests/Unit/Payroll/PayrollAdjustmentServiceTest.php already proves
 * PayrollPeriodService::close() and PayrollAdjustmentService::
 * adjustInNextPeriod() correct in isolation — including a byte-for-byte
 * untouched-original proof
 * (test_adjust_in_next_period_never_touches_the_original_closed_entry_or_its_lines())
 * built on hand-assembled PayrollEntry/PayrollEntryLine fixtures. This test
 * instead drives the REAL public API stack — PayrollPeriodService::
 * calculate()/close() then PayrollAdjustmentService::adjustInNextPeriod() —
 * across TWO consecutive, genuinely calculated biweekly payroll periods,
 * proving the whole pipeline (not hand-assembled rows) honors that
 * immutability guarantee end to end.
 */
class PayrollCloseThenAdjustmentTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PayrollConceptCatalogSeeder::class);

        $this->company = Company::factory()->create();
        app(CurrentCompany::class)->set($this->company);

        $this->actor = User::factory()->create();
    }

    /**
     * Employee A's shape from BiweeklyPayrollCalculationTest, reused
     * verbatim for simplicity — this test isn't about proration or
     * overtime, it's about the close/adjust lifecycle: plain salary, one
     * continuous contract, no overtime, no deductions.
     */
    private function createEmployee(float $baseSalary): Employee
    {
        $employee = Employee::factory()->create(['company_id' => $this->company->id]);

        EmploymentContract::factory()->create([
            'company_id' => $this->company->id,
            'employee_id' => $employee->id,
            'start_date' => '2025-01-01',
            'end_date' => null,
            'base_salary' => $baseSalary,
        ]);

        return $employee;
    }

    /**
     * A realistic (not exhaustive) spread of attendance across the given
     * dates — enough to satisfy PayrollCalculationService::
     * assertHasAttendanceOrNoveltyCoverage(), which only requires at least
     * one AttendanceRecord somewhere inside [period.start_date,
     * period.end_date]. Attendance data has no bearing on the base-salary
     * money math here.
     *
     * @param  list<string>  $dates
     */
    private function seedAttendance(Employee $employee, array $dates): void
    {
        foreach ($dates as $date) {
            AttendanceRecord::factory()->create([
                'company_id' => $this->company->id,
                'employee_id' => $employee->id,
                'date' => $date,
            ]);
        }
    }

    public function test_a_closed_period_stays_byte_for_byte_untouched_after_a_next_period_adjustment_corrects_it()
    {
        // ------------------------------------------------------------
        // Fixture: one employee, two consecutive real biweekly periods.
        // ------------------------------------------------------------

        // Daily rate = 3,000,000 / 30 days in April = 100,000/day.
        // Period 1 (Apr 1-15, 15 days): 100,000 * 15 = 1,500,000.
        // Period 2 (Apr 16-30, 15 days): 100,000 * 15 = 1,500,000.
        $employee = $this->createEmployee(baseSalary: 3000000);

        $period1 = PayrollPeriod::factory()->create([
            'company_id' => $this->company->id,
            'period_type' => 'biweekly',
            'start_date' => '2026-04-01',
            'end_date' => '2026-04-15',
            'status' => 'open',
        ]);

        $period2 = PayrollPeriod::factory()->create([
            'company_id' => $this->company->id,
            'period_type' => 'biweekly',
            'start_date' => '2026-04-16',
            'end_date' => '2026-04-30',
            'status' => 'open',
        ]);

        $this->seedAttendance($employee, ['2026-04-01', '2026-04-06', '2026-04-13']);
        $this->seedAttendance($employee, ['2026-04-16', '2026-04-21', '2026-04-28']);

        $periodService = app(PayrollPeriodService::class);
        $adjustmentService = app(PayrollAdjustmentService::class);

        // ------------------------------------------------------------
        // Period 1: calculate() then close().
        // ------------------------------------------------------------

        $periodService->calculate($period1, $this->actor);
        $periodService->close($period1->fresh(), $this->actor);

        $entryP1 = PayrollEntry::query()
            ->where('payroll_period_id', $period1->id)
            ->where('employee_id', $employee->id)
            ->firstOrFail();

        $this->assertSame('calculated', $entryP1->status);
        $this->assertEqualsWithDelta(1500000.0, (float) $entryP1->gross_total, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $entryP1->deductions_total, 0.0001);
        $this->assertEqualsWithDelta(1500000.0, (float) $entryP1->net_total, 0.01);
        $this->assertSame('closed', $period1->fresh()->status);

        // ------------------------------------------------------------
        // Snapshot Period 1's entry AND all its lines, BEFORE the
        // adjustment — this is the literal acceptance criterion: they must
        // be byte-for-byte identical after the correction.
        // ------------------------------------------------------------

        $entryP1SnapshotBefore = PayrollEntry::query()->findOrFail($entryP1->id)->toArray();
        $linesP1SnapshotBefore = PayrollEntryLine::query()
            ->where('payroll_entry_id', $entryP1->id)
            ->orderBy('id')
            ->get()
            ->toArray();
        // Exactly one BASE_SALARY line for this single-contract, no
        // overtime, no deduction employee shape.
        $this->assertCount(1, $linesP1SnapshotBefore);

        // ------------------------------------------------------------
        // Period 2: calculate() only (must exist so adjustInNextPeriod()
        // has a target entry to attach a line to, per commit 12's
        // established precondition).
        // ------------------------------------------------------------

        $periodService->calculate($period2, $this->actor);

        $entryP2 = PayrollEntry::query()
            ->where('payroll_period_id', $period2->id)
            ->where('employee_id', $employee->id)
            ->firstOrFail();

        $this->assertSame('calculated', $entryP2->status);

        // Period 2's own totals BEFORE the adjustment — recomputed here
        // (not merely assumed) so the post-adjustment assertion below is a
        // real "old + delta = new" check, not an approximation.
        $grossTotalP2Before = (float) $entryP2->gross_total;
        $netTotalP2Before = (float) $entryP2->net_total;
        $lineCountP2Before = $entryP2->lines()->count();

        $this->assertEqualsWithDelta(1500000.0, $grossTotalP2Before, 0.01);
        $this->assertEqualsWithDelta(1500000.0, $netTotalP2Before, 0.01);
        $this->assertSame(1, $lineCountP2Before);

        // ------------------------------------------------------------
        // The correction: a forgotten bonus discovered after Period 1
        // closed, corrected via the default ADR-026 mechanism.
        // ------------------------------------------------------------

        $adjustmentConcept = PayrollConceptDefinition::factory()->create([
            'company_id' => $this->company->id,
            'type' => 'earning',
        ]);
        $adjustmentAmount = 150000.0;
        $reason = 'Bono de puntualidad olvidado en el cálculo original del periodo.';

        $adjustment = $adjustmentService->adjustInNextPeriod(
            closedEntry: $entryP1,
            createdBy: $this->actor,
            conceptId: $adjustmentConcept->id,
            amount: $adjustmentAmount,
            type: 'earning',
            reason: $reason,
        );

        // ------------------------------------------------------------
        // Period 1's entry and ALL its lines: byte-for-byte identical to
        // the pre-adjustment snapshot.
        // ------------------------------------------------------------

        $entryP1SnapshotAfter = PayrollEntry::query()->findOrFail($entryP1->id)->toArray();
        $linesP1SnapshotAfter = PayrollEntryLine::query()
            ->where('payroll_entry_id', $entryP1->id)
            ->orderBy('id')
            ->get()
            ->toArray();

        $this->assertSame($entryP1SnapshotBefore, $entryP1SnapshotAfter);
        $this->assertSame($linesP1SnapshotBefore, $linesP1SnapshotAfter);
        $this->assertCount(1, $linesP1SnapshotAfter);

        // ------------------------------------------------------------
        // Period 2's entry: one ADDITIONAL line beyond its own normal
        // calculation, with the correct concept_id/amount/type, and totals
        // equal to its own pre-adjustment totals plus the adjustment.
        // ------------------------------------------------------------

        $entryP2Fresh = $entryP2->fresh();
        $newLine = PayrollEntryLine::query()
            ->where('payroll_entry_id', $entryP2->id)
            ->where('concept_id', $adjustmentConcept->id)
            ->first();

        $this->assertNotNull($newLine);
        $this->assertSame('earning', $newLine->type);
        $this->assertNull($newLine->contract_id);
        $this->assertNull($newLine->quantity);
        $this->assertNull($newLine->rate);
        $this->assertEqualsWithDelta($adjustmentAmount, (float) $newLine->amount, 0.0001);

        $this->assertSame($lineCountP2Before + 1, $entryP2Fresh->lines()->count());
        $this->assertEqualsWithDelta($grossTotalP2Before + $adjustmentAmount, (float) $entryP2Fresh->gross_total, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $entryP2Fresh->deductions_total, 0.0001);
        $this->assertEqualsWithDelta($netTotalP2Before + $adjustmentAmount, (float) $entryP2Fresh->net_total, 0.01);

        // ------------------------------------------------------------
        // The PayrollAdjustment row itself.
        // ------------------------------------------------------------

        $this->assertSame('next_period', $adjustment->mechanism);
        // payroll_entry_id points at the ORIGINAL entry being corrected
        // (Period 1's), never the target entry the money landed on.
        $this->assertSame($entryP1->id, $adjustment->payroll_entry_id);
        $this->assertSame($period2->id, $adjustment->applied_in_period_id);
        $this->assertSame($reason, $adjustment->reason);

        $this->assertSame(
            1,
            PayrollAdjustment::query()
                ->where('payroll_entry_id', $entryP1->id)
                ->where('applied_in_period_id', $period2->id)
                ->count(),
        );

        // ------------------------------------------------------------
        // Exactly the right number of AuditLog rows across the WHOLE flow:
        // calculate(period1), close(period1), calculate(period2),
        // adjustInNextPeriod() — one audit row per transition, per
        // .ai/16-AUDIT.md, so exactly 4 for this entire flow.
        // ------------------------------------------------------------

        $this->assertSame(
            1,
            AuditLog::query()->where('entity_id', $period1->id)->where('action', 'payroll_period.calculated')->count(),
        );
        $this->assertSame(
            1,
            AuditLog::query()->where('entity_id', $period1->id)->where('action', 'payroll_period.closed')->count(),
        );
        $this->assertSame(
            1,
            AuditLog::query()->where('entity_id', $period2->id)->where('action', 'payroll_period.calculated')->count(),
        );
        $this->assertSame(
            1,
            AuditLog::query()->where('entity_id', $adjustment->id)->where('action', 'payroll_adjustment.created')->count(),
        );
        $this->assertSame(4, AuditLog::query()->count());

        // ------------------------------------------------------------
        // Regression check (immutability story, cheap and directly
        // relevant): a closed period genuinely can't be re-closed/silently
        // re-processed. Exercised here directly against the real closed
        // period this test just produced.
        // ------------------------------------------------------------

        $this->expectException(InvalidPayrollPeriodStatusException::class);
        $periodService->close($period1->fresh(), $this->actor);
    }

    /**
     * A standalone, narrower regression check for the same "closed period
     * can't be silently re-processed" story as the main scenario's tail
     * assertion above, isolated into its own test so a failure here reads
     * unambiguously rather than aborting the full close-then-adjust
     * narrative.
     */
    public function test_closing_an_already_closed_period_a_second_time_throws()
    {
        $employee = $this->createEmployee(baseSalary: 3000000);

        $period = PayrollPeriod::factory()->create([
            'company_id' => $this->company->id,
            'period_type' => 'biweekly',
            'start_date' => '2026-04-01',
            'end_date' => '2026-04-15',
            'status' => 'open',
        ]);

        $this->seedAttendance($employee, ['2026-04-01', '2026-04-06', '2026-04-13']);

        $periodService = app(PayrollPeriodService::class);
        $periodService->calculate($period, $this->actor);
        $closedPeriod = $periodService->close($period->fresh(), $this->actor);

        $this->assertSame('closed', $closedPeriod->status);

        $this->expectException(InvalidPayrollPeriodStatusException::class);

        $periodService->close($closedPeriod->fresh(), $this->actor);
    }
}
