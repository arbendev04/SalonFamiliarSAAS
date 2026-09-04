<?php

namespace Tests\Feature\Payroll;

use App\Models\Company;
use App\Models\Employee;
use App\Models\EmploymentContract;
use App\Models\GeneratedDocument;
use App\Models\PayrollConceptDefinition;
use App\Models\PayrollEntry;
use App\Models\PayrollEntryLine;
use App\Models\PayrollPeriod;
use App\Models\User;
use App\Services\Payroll\PayrollPeriodService;
use App\Services\Tenancy\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Proves the post-commit receipt-generation loop wired into
 * PayrollPeriodService::close() (commit 6 of the Fase 11 plan,
 * PayrollPeriodService::generateReceiptsForClosedPeriod()) never lets one
 * employee's receipt-generation failure block the period from closing, nor
 * block any other employee's own receipt.
 *
 * Forces the failure the simplest reliable way available: one employee's
 * PayrollEntry is left with zero payroll_entry_lines, which
 * PayrollReceiptService::generate() already rejects on its own with
 * MissingRequiredReceiptDataException (tests/Unit/Pdf/PayrollReceiptServiceTest.php
 * already covers that guard in isolation) — this test only exercises what
 * the WIRING does with that failure once it happens inside close()'s
 * post-commit loop: catch it, log it, and keep going.
 */
class PayrollReceiptGenerationFailureTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake();

        $this->company = Company::factory()->create();
        app(CurrentCompany::class)->set($this->company);

        $this->actor = User::factory()->create();
    }

    public function test_one_employees_receipt_failure_does_not_block_the_close_or_the_other_employees_receipt(): void
    {
        $period = PayrollPeriod::factory()->create([
            'company_id' => $this->company->id,
            'status' => 'calculated',
        ]);

        $concept = PayrollConceptDefinition::factory()->create([
            'company_id' => $this->company->id,
            'type' => 'earning',
        ]);

        // Employee A: a PayrollEntry with zero lines -> generate() throws
        // MissingRequiredReceiptDataException for this one employee only.
        $employeeA = Employee::factory()->create(['company_id' => $this->company->id]);
        $contractA = EmploymentContract::factory()->create([
            'company_id' => $this->company->id,
            'employee_id' => $employeeA->id,
        ]);
        $entryA = PayrollEntry::factory()->create([
            'company_id' => $this->company->id,
            'payroll_period_id' => $period->id,
            'employee_id' => $employeeA->id,
            'contract_id' => $contractA->id,
            'status' => 'calculated',
        ]);

        // Employee B: a normal entry with one line -> its receipt succeeds
        // regardless of what happened to A.
        $employeeB = Employee::factory()->create(['company_id' => $this->company->id]);
        $contractB = EmploymentContract::factory()->create([
            'company_id' => $this->company->id,
            'employee_id' => $employeeB->id,
        ]);
        $entryB = PayrollEntry::factory()->create([
            'company_id' => $this->company->id,
            'payroll_period_id' => $period->id,
            'employee_id' => $employeeB->id,
            'contract_id' => $contractB->id,
            'status' => 'calculated',
        ]);

        PayrollEntryLine::factory()->create([
            'company_id' => $this->company->id,
            'payroll_entry_id' => $entryB->id,
            'concept_id' => $concept->id,
            'contract_id' => $contractB->id,
            'type' => 'earning',
            'quantity' => null,
            'rate' => null,
            'amount' => 100000,
        ]);

        $service = app(PayrollPeriodService::class);
        $result = $service->close($period, $this->actor);

        // The close() itself must succeed unconditionally, regardless of A's
        // receipt failure.
        $this->assertSame('closed', $result->status);
        $this->assertSame('closed', $period->fresh()->status);

        // A: zero GeneratedDocument rows — the failure was skipped, not
        // silently swallowed into a broken document.
        $this->assertSame(
            0,
            GeneratedDocument::query()
                ->where('reference_entity_type', 'payroll_entry')
                ->where('reference_entity_id', $entryA->id)
                ->count(),
        );

        // B: exactly one version-1 GeneratedDocument, unaffected by A's
        // failure.
        $documentsB = GeneratedDocument::query()
            ->where('reference_entity_type', 'payroll_entry')
            ->where('reference_entity_id', $entryB->id)
            ->get();

        $this->assertCount(1, $documentsB);
        $this->assertSame(1, $documentsB->first()->version);
        Storage::assertExists($documentsB->first()->storage_ref);
    }
}
