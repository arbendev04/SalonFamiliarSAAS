<?php

namespace Tests\Unit\Pdf;

use App\Exceptions\InvalidPayrollPeriodStatusException;
use App\Exceptions\MissingRequiredReceiptDataException;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmploymentContract;
use App\Models\GeneratedDocument;
use App\Models\PayrollAdjustment;
use App\Models\PayrollConceptDefinition;
use App\Models\PayrollEntry;
use App\Models\PayrollEntryLine;
use App\Models\PayrollPeriod;
use App\Models\User;
use App\Services\Pdf\PayrollReceiptService;
use App\Services\Tenancy\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakePdfGenerator;
use Tests\TestCase;

/**
 * Covers App\Services\Pdf\PayrollReceiptService::generate() — commit 5 of the
 * Fase 11 plan. Uses a FakePdfGenerator (see tests/Support/FakePdfGenerator.php)
 * instead of the real dompdf-backed binding so these are true unit tests of
 * the service's own logic (guards, version resolution, $data assembly,
 * storage), not of PDF rendering (already covered by
 * tests/Unit/Pdf/DompdfPdfGeneratorTest.php).
 */
class PayrollReceiptServiceTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $actor;

    private FakePdfGenerator $pdfGenerator;

    private PayrollReceiptService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake();

        $this->company = Company::factory()->create();
        app(CurrentCompany::class)->set($this->company);

        $this->actor = User::factory()->create();
        $this->pdfGenerator = new FakePdfGenerator;
        $this->service = new PayrollReceiptService($this->pdfGenerator);
    }

    /**
     * Builds a CLOSED payroll_entries row with one earning line and one
     * deduction line, for one employee (with a branch), in a period running
     * 2026-01-01..2026-01-15.
     */
    private function closedEntryWithLines(): PayrollEntry
    {
        $branch = Branch::factory()->create(['company_id' => $this->company->id]);
        $employee = Employee::factory()->create([
            'company_id' => $this->company->id,
            'branch_id' => $branch->id,
            'full_name' => 'Ana María Gómez',
            'document_type' => 'CC',
            'national_id' => '1020304050',
        ]);
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
            'deductions_total' => 50000,
            'net_total' => 450000,
        ]);

        $earningConcept = PayrollConceptDefinition::factory()->create([
            'company_id' => $this->company->id,
            'name' => 'Salario base',
            'type' => 'earning',
        ]);
        $deductionConcept = PayrollConceptDefinition::factory()->create([
            'company_id' => $this->company->id,
            'name' => 'Préstamo',
            'type' => 'deduction',
        ]);

        PayrollEntryLine::factory()->create([
            'company_id' => $this->company->id,
            'payroll_entry_id' => $entry->id,
            'concept_id' => $earningConcept->id,
            'contract_id' => $contract->id,
            'type' => 'earning',
            'quantity' => 15,
            'rate' => 33333.3333,
            'amount' => 500000,
        ]);

        PayrollEntryLine::factory()->create([
            'company_id' => $this->company->id,
            'payroll_entry_id' => $entry->id,
            'concept_id' => $deductionConcept->id,
            'contract_id' => $contract->id,
            'type' => 'deduction',
            'quantity' => null,
            'rate' => null,
            'amount' => 50000,
        ]);

        return $entry;
    }

    // ----------------------------------------------------------------
    // Happy path
    // ----------------------------------------------------------------

    public function test_generate_creates_a_version_1_document_and_stores_the_pdf(): void
    {
        $entry = $this->closedEntryWithLines();

        $document = $this->service->generate($entry, $this->actor);

        $this->assertInstanceOf(GeneratedDocument::class, $document);
        $this->assertSame(1, $document->version);
        $this->assertSame('payroll_receipt', $document->type);
        $this->assertSame('payroll_entry', $document->reference_entity_type);
        $this->assertSame($entry->id, $document->reference_entity_id);
        $this->assertSame($this->actor->id, $document->generated_by);
        $this->assertSame($this->company->id, $document->company_id);

        $expectedStorageRef = "receipts/{$entry->company_id}/{$entry->employee_id}/{$entry->id}/v1.pdf";
        $this->assertSame($expectedStorageRef, $document->storage_ref);

        Storage::assertExists($expectedStorageRef);
        $this->assertSame(1, GeneratedDocument::query()->count());
    }

    public function test_generate_called_three_times_produces_versions_1_then_2_then_3_and_keeps_all_files(): void
    {
        $entry = $this->closedEntryWithLines();

        $first = $this->service->generate($entry, $this->actor);
        $second = $this->service->generate($entry, $this->actor);
        $third = $this->service->generate($entry, $this->actor);

        $this->assertSame(1, $first->version);
        $this->assertSame(2, $second->version);
        $this->assertSame(3, $third->version);

        $this->assertSame(
            "receipts/{$entry->company_id}/{$entry->employee_id}/{$entry->id}/v1.pdf",
            $first->storage_ref,
        );
        $this->assertSame(
            "receipts/{$entry->company_id}/{$entry->employee_id}/{$entry->id}/v2.pdf",
            $second->storage_ref,
        );
        $this->assertSame(
            "receipts/{$entry->company_id}/{$entry->employee_id}/{$entry->id}/v3.pdf",
            $third->storage_ref,
        );

        Storage::assertExists($first->storage_ref);
        Storage::assertExists($second->storage_ref);
        Storage::assertExists($third->storage_ref);
        $this->assertSame(3, GeneratedDocument::query()->count());
    }

    // ----------------------------------------------------------------
    // Guards
    // ----------------------------------------------------------------

    public function test_generate_throws_invalid_payroll_period_status_exception_when_period_is_not_closed(): void
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
        ]);

        $this->expectException(InvalidPayrollPeriodStatusException::class);

        $this->service->generate($entry, $this->actor);
    }

    public function test_generate_throws_invalid_payroll_period_status_exception_when_period_is_approved(): void
    {
        $employee = Employee::factory()->create(['company_id' => $this->company->id]);
        $period = PayrollPeriod::factory()->create([
            'company_id' => $this->company->id,
            'status' => 'approved',
        ]);
        $entry = PayrollEntry::factory()->create([
            'company_id' => $this->company->id,
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
        ]);

        $this->expectException(InvalidPayrollPeriodStatusException::class);

        $this->service->generate($entry, $this->actor);
    }

    public function test_generate_throws_missing_required_receipt_data_exception_when_entry_has_no_lines(): void
    {
        $employee = Employee::factory()->create(['company_id' => $this->company->id]);
        $period = PayrollPeriod::factory()->create([
            'company_id' => $this->company->id,
            'status' => 'closed',
        ]);
        $entry = PayrollEntry::factory()->create([
            'company_id' => $this->company->id,
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
        ]);

        $this->expectException(MissingRequiredReceiptDataException::class);

        $this->service->generate($entry, $this->actor);
    }

    // ----------------------------------------------------------------
    // $data shape passed to PdfGenerator::render()
    // ----------------------------------------------------------------

    public function test_generate_passes_correct_company_employee_period_and_totals_to_the_pdf_generator(): void
    {
        $entry = $this->closedEntryWithLines();

        $this->service->generate($entry, $this->actor);

        $data = $this->pdfGenerator->lastData;

        $this->assertSame('pdf.payroll-receipt', $this->pdfGenerator->lastView);
        $this->assertNotNull($data);

        $this->assertSame($this->company->legal_name, $data['company']['legal_name']);
        $this->assertSame($this->company->tax_id, $data['company']['tax_id']);

        $this->assertSame('Ana María Gómez', $data['employee']['full_name']);
        $this->assertSame('CC', $data['employee']['document_type']);
        $this->assertSame('1020304050', $data['employee']['national_id']);

        $this->assertSame('2026-01-01', $data['period']['start_date']);
        $this->assertSame('2026-01-15', $data['period']['end_date']);

        $this->assertEqualsWithDelta(500000.0, $data['totals']['gross'], 0.0001);
        $this->assertEqualsWithDelta(50000.0, $data['totals']['deductions'], 0.0001);
        $this->assertEqualsWithDelta(450000.0, $data['totals']['net'], 0.0001);

        $this->assertSame(1, $data['version']);
        $this->assertNotEmpty($data['generated_at']);
    }

    public function test_generate_passes_each_line_type_description_and_amount_to_the_pdf_generator(): void
    {
        $entry = $this->closedEntryWithLines();

        $this->service->generate($entry, $this->actor);

        $lines = $this->pdfGenerator->lastData['lines'];

        $this->assertCount(2, $lines);

        $earningLine = collect($lines)->firstWhere('type', 'earning');
        $this->assertSame('Salario base', $earningLine['description']);
        $this->assertEqualsWithDelta(15.0, $earningLine['quantity'], 0.0001);
        $this->assertEqualsWithDelta(33333.3333, $earningLine['rate'], 0.001);
        $this->assertEqualsWithDelta(500000.0, $earningLine['amount'], 0.0001);

        $deductionLine = collect($lines)->firstWhere('type', 'deduction');
        $this->assertSame('Préstamo', $deductionLine['description']);
        $this->assertNull($deductionLine['quantity']);
        $this->assertNull($deductionLine['rate']);
        $this->assertEqualsWithDelta(50000.0, $deductionLine['amount'], 0.0001);
    }

    public function test_generate_with_no_payroll_adjustments_passes_an_empty_observations_array(): void
    {
        $entry = $this->closedEntryWithLines();

        $this->service->generate($entry, $this->actor);

        $data = $this->pdfGenerator->lastData;

        $this->assertIsArray($data['observations']);
        $this->assertSame([], $data['observations']);
    }

    public function test_generate_with_a_payroll_adjustment_extracts_the_amount_from_corrected_value_into_observations(): void
    {
        $entry = $this->closedEntryWithLines();

        PayrollAdjustment::factory()->create([
            'company_id' => $this->company->id,
            'payroll_entry_id' => $entry->id,
            'mechanism' => 'reopen',
            'original_value' => ['amount' => 500000],
            'corrected_value' => ['amount' => 550000],
            'reason' => 'Horas extra mal autorizadas en el cierre original.',
            'created_by' => $this->actor->id,
            'applied_in_period_id' => null,
        ]);

        $this->service->generate($entry, $this->actor);

        $observations = $this->pdfGenerator->lastData['observations'];

        $this->assertCount(1, $observations);
        $this->assertSame('Horas extra mal autorizadas en el cierre original.', $observations[0]['reason']);
        $this->assertEqualsWithDelta(550000.0, $observations[0]['corrected_value'], 0.0001);
    }

    // ----------------------------------------------------------------
    // Known tenant-scoping bug fix (eager-load of lines.concept)
    // ----------------------------------------------------------------

    public function test_generate_resolves_a_platform_default_concept_name_on_a_line(): void
    {
        $entry = $this->closedEntryWithLines();

        // A platform-default (company_id = null) concept, same DIRECTO/GLOBAL
        // shape as the seeded catalog (PayrollConceptCatalogSeeder). Without
        // ->withoutGlobalScope('company') on the lines.concept eager-load,
        // BelongsToCompany's global scope excludes this row even through the
        // belongsTo() relation and concept.name would render blank.
        $platformConcept = PayrollConceptDefinition::factory()->create([
            'company_id' => null,
            'code' => 'BASE_SALARY',
            'name' => 'Salario base',
            'type' => 'earning',
        ]);

        PayrollEntryLine::factory()->create([
            'company_id' => $this->company->id,
            'payroll_entry_id' => $entry->id,
            'concept_id' => $platformConcept->id,
            'contract_id' => $entry->contract_id,
            'type' => 'earning',
            'quantity' => null,
            'rate' => null,
            'amount' => 123456,
        ]);

        $this->service->generate($entry, $this->actor);

        $lines = $this->pdfGenerator->lastData['lines'];
        $platformLine = collect($lines)->first(fn (array $line) => (float) $line['amount'] === 123456.0);

        $this->assertNotNull($platformLine);
        $this->assertSame('Salario base', $platformLine['description']);
    }

    // ----------------------------------------------------------------
    // No disk hardcoded — Storage::put() uses whatever's configured
    // ----------------------------------------------------------------

    public function test_generate_stores_the_exact_bytes_returned_by_the_pdf_generator(): void
    {
        $pdfGenerator = new FakePdfGenerator('%PDF-1.4 distinctive-fixture-bytes');
        $service = new PayrollReceiptService($pdfGenerator);
        $entry = $this->closedEntryWithLines();

        $document = $service->generate($entry, $this->actor);

        Storage::assertExists($document->storage_ref);
        $this->assertSame('%PDF-1.4 distinctive-fixture-bytes', Storage::get($document->storage_ref));
    }
}
