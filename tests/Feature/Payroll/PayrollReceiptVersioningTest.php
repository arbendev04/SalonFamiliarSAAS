<?php

namespace Tests\Feature\Payroll;

use App\Models\AttendanceRecord;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmploymentContract;
use App\Models\GeneratedDocument;
use App\Models\PayrollEntry;
use App\Models\PayrollPeriod;
use App\Models\SalaryHistory;
use App\Models\User;
use App\Services\Payroll\PayrollAdjustmentService;
use App\Services\Payroll\PayrollCalculationService;
use App\Services\Payroll\PayrollPeriodService;
use App\Services\Tenancy\CurrentCompany;
use Database\Seeders\PayrollConceptCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The roadmap-required acceptance test for commit 6 of the Fase 11 plan
 * (composed-knitting-dusk.md, "Verificación": "Reabrir -> corregir -> cerrar
 * de nuevo genera v2 de TODOS los comprobantes del periodo, v1 permanece
 * intacto y accesible").
 *
 * Drives the real public API stack end to end — PayrollPeriodService::
 * calculate()/close()/reopen(), PayrollCalculationService::
 * calculateForEmployee() (the free recalculation a reopened period allows),
 * and PayrollAdjustmentService::recordReopenCorrection() (the audit trail
 * for that exceptional path, per ADR-026) — across two employees, proving
 * PayrollPeriodService::generateReceiptsForClosedPeriod() needs no
 * special-casing for "this is a reclose": every close() unconditionally
 * walks every current PayrollEntry and lets PayrollReceiptService::generate()
 * resolve the next version via MAX(version)+1, so v1 on the first close()
 * and v2 on the reopen+correct+reclose fall out of the exact same
 * branch-free loop.
 */
class PayrollReceiptVersioningTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake();

        $this->seed(PayrollConceptCatalogSeeder::class);

        $this->company = Company::factory()->create();
        app(CurrentCompany::class)->set($this->company);

        $this->actor = User::factory()->create();
    }

    private function createEmployeeWithContract(float $baseSalary): array
    {
        $employee = Employee::factory()->create(['company_id' => $this->company->id]);

        $contract = EmploymentContract::factory()->create([
            'company_id' => $this->company->id,
            'employee_id' => $employee->id,
            'start_date' => '2025-01-01',
            'end_date' => null,
            'base_salary' => $baseSalary,
        ]);

        return [$employee, $contract];
    }

    /**
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

    public function test_reopen_correct_reclose_produces_v2_for_every_entry_while_v1_stays_byte_identical(): void
    {
        // ------------------------------------------------------------
        // Fixture: two employees, one real biweekly period.
        // ------------------------------------------------------------

        [$employeeA] = $this->createEmployeeWithContract(baseSalary: 3000000);
        [$employeeB] = $this->createEmployeeWithContract(baseSalary: 2000000);

        $period = PayrollPeriod::factory()->create([
            'company_id' => $this->company->id,
            'period_type' => 'biweekly',
            'start_date' => '2026-05-01',
            'end_date' => '2026-05-15',
            'status' => 'open',
        ]);

        $this->seedAttendance($employeeA, ['2026-05-01', '2026-05-06', '2026-05-13']);
        $this->seedAttendance($employeeB, ['2026-05-01', '2026-05-08', '2026-05-13']);

        $periodService = app(PayrollPeriodService::class);
        $calculationService = app(PayrollCalculationService::class);
        $adjustmentService = app(PayrollAdjustmentService::class);

        // ------------------------------------------------------------
        // First close(): v1 for both entries.
        // ------------------------------------------------------------

        $periodService->calculate($period, $this->actor);
        $periodService->close($period->fresh(), $this->actor);

        $entryA = PayrollEntry::query()
            ->where('payroll_period_id', $period->id)
            ->where('employee_id', $employeeA->id)
            ->firstOrFail();
        $entryB = PayrollEntry::query()
            ->where('payroll_period_id', $period->id)
            ->where('employee_id', $employeeB->id)
            ->firstOrFail();

        $documentsAAfterFirstClose = GeneratedDocument::query()
            ->where('reference_entity_type', 'payroll_entry')
            ->where('reference_entity_id', $entryA->id)
            ->get();
        $documentsBAfterFirstClose = GeneratedDocument::query()
            ->where('reference_entity_type', 'payroll_entry')
            ->where('reference_entity_id', $entryB->id)
            ->get();

        $this->assertCount(1, $documentsAAfterFirstClose);
        $this->assertCount(1, $documentsBAfterFirstClose);
        $this->assertSame(1, $documentsAAfterFirstClose->first()->version);
        $this->assertSame(1, $documentsBAfterFirstClose->first()->version);

        $documentAv1 = $documentsAAfterFirstClose->first();
        $v1StorageRef = $documentAv1->storage_ref;
        $v1BytesBeforeReopen = Storage::get($v1StorageRef);
        $this->assertNotEmpty($v1BytesBeforeReopen);

        $netTotalBeforeCorrection = (float) $entryA->net_total;

        // ------------------------------------------------------------
        // Reopen + a REAL correction against employee A: a retroactive
        // salary raise recorded as a new SalaryHistory revision, then
        // freely recalculated while the period is 'reopened' (commit 11's
        // established precondition), then the audit trail recorded via the
        // EXCEPTIONAL ADR-026 mechanism for this path.
        // ------------------------------------------------------------

        $periodService->reopen($period->fresh(), $this->actor, 'Corrección salarial retroactiva de mayo.');

        SalaryHistory::factory()->create([
            'company_id' => $this->company->id,
            'contract_id' => $entryA->contract_id,
            'effective_from' => '2026-05-01',
            'effective_to' => null,
            'base_salary' => 3300000,
            'reason' => 'Ajuste salarial retroactivo.',
        ]);

        $recalculatedEntryA = $calculationService->calculateForEmployee($period->fresh(), $employeeA);

        $netTotalAfterCorrection = (float) $recalculatedEntryA->net_total;

        $adjustmentService->recordReopenCorrection(
            entry: $recalculatedEntryA,
            createdBy: $this->actor,
            originalValue: ['net_total' => $netTotalBeforeCorrection],
            correctedValue: ['net_total' => $netTotalAfterCorrection],
            reason: 'Ajuste salarial retroactivo.',
        );

        // The correction must have actually changed something concrete —
        // otherwise this test would not be exercising a real correction at
        // all. Data-fidelity of what PayrollReceiptService renders from a
        // PayrollEntry is already proven by
        // tests/Unit/Pdf/PayrollReceiptServiceTest.php; this test only needs
        // to know entry A's underlying settlement genuinely differs.
        $this->assertNotEqualsWithDelta($netTotalBeforeCorrection, $netTotalAfterCorrection, 0.01);

        // ------------------------------------------------------------
        // Reclose(): v2 for BOTH entries — including employee B, who was
        // never touched by the correction — per the plan's confirmed
        // design (section "Contexto", decision 3): every close() regenerates
        // the whole period's receipts, not just the corrected entry.
        // ------------------------------------------------------------

        $periodService->close($period->fresh(), $this->actor);

        $documentsAAfterReclose = GeneratedDocument::query()
            ->where('reference_entity_type', 'payroll_entry')
            ->where('reference_entity_id', $entryA->id)
            ->orderBy('version')
            ->get();
        $documentsBAfterReclose = GeneratedDocument::query()
            ->where('reference_entity_type', 'payroll_entry')
            ->where('reference_entity_id', $entryB->id)
            ->orderBy('version')
            ->get();

        $this->assertCount(2, $documentsAAfterReclose);
        $this->assertCount(2, $documentsBAfterReclose);
        $this->assertSame([1, 2], $documentsAAfterReclose->pluck('version')->all());
        $this->assertSame([1, 2], $documentsBAfterReclose->pluck('version')->all());

        // ------------------------------------------------------------
        // v1 stays byte-for-byte identical: same storage_ref, same bytes,
        // never overwritten by the reclose.
        // ------------------------------------------------------------

        $documentAv1AfterReclose = $documentsAAfterReclose->firstWhere('version', 1);
        $this->assertSame($v1StorageRef, $documentAv1AfterReclose->storage_ref);
        Storage::assertExists($v1StorageRef);
        $this->assertSame($v1BytesBeforeReopen, Storage::get($v1StorageRef));

        // ------------------------------------------------------------
        // Every document, both entries, both versions, independently
        // retrievable from storage.
        // ------------------------------------------------------------

        foreach ($documentsAAfterReclose->concat($documentsBAfterReclose) as $document) {
            Storage::assertExists($document->storage_ref);
            $this->assertNotEmpty(Storage::get($document->storage_ref));
        }

        // v2's storage_ref must be a distinct file from v1's.
        $documentAv2AfterReclose = $documentsAAfterReclose->firstWhere('version', 2);
        $this->assertNotSame($documentAv1AfterReclose->storage_ref, $documentAv2AfterReclose->storage_ref);
    }
}
