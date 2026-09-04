<?php

namespace Tests\Feature;

use App\Exceptions\GeneratedDocumentImmutableException;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmploymentContract;
use App\Models\GeneratedDocument;
use App\Models\PayrollAdjustment;
use App\Models\PayrollEntry;
use App\Models\PayrollPeriod;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GeneratedDocumentImmutabilityTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private PayrollEntry $entry;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $employee = Employee::factory()->create(['company_id' => $this->company->id]);
        $contract = EmploymentContract::factory()->create([
            'company_id' => $this->company->id,
            'employee_id' => $employee->id,
        ]);
        $period = PayrollPeriod::factory()->create(['company_id' => $this->company->id]);
        $this->user = User::factory()->create();
        $this->entry = PayrollEntry::factory()->create([
            'company_id' => $this->company->id,
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
            'contract_id' => $contract->id,
        ]);
    }

    private function createDocument(): GeneratedDocument
    {
        return GeneratedDocument::factory()->create([
            'company_id' => $this->company->id,
            'reference_entity_id' => $this->entry->id,
            'generated_by' => $this->user->id,
        ]);
    }

    public function test_updating_a_generated_document_instance_throws()
    {
        $document = $this->createDocument();

        $this->expectException(GeneratedDocumentImmutableException::class);

        $document->update(['storage_ref' => 'tampered.pdf']);
    }

    public function test_deleting_a_generated_document_instance_throws()
    {
        $document = $this->createDocument();

        $this->expectException(GeneratedDocumentImmutableException::class);

        $document->delete();
    }

    public function test_updating_via_query_builder_throws()
    {
        $document = $this->createDocument();

        $this->expectException(GeneratedDocumentImmutableException::class);

        GeneratedDocument::query()->where('id', $document->id)->update(['storage_ref' => 'tampered.pdf']);
    }

    public function test_deleting_via_query_builder_throws()
    {
        $document = $this->createDocument();

        $this->expectException(GeneratedDocumentImmutableException::class);

        GeneratedDocument::query()->where('id', $document->id)->delete();
    }

    public function test_creating_a_document_auto_populates_generated_at()
    {
        $document = $this->createDocument();

        $this->assertNotNull($document->generated_at);
        $this->assertArrayNotHasKey('updated_at', $document->getAttributes());
    }

    public function test_reference_entity_resolves_to_the_correct_payroll_entry()
    {
        $document = $this->createDocument();

        $this->assertTrue($document->referenceEntity->is($this->entry));
        $this->assertInstanceOf(PayrollEntry::class, $document->referenceEntity);
    }

    public function test_a_document_belongs_to_its_generator()
    {
        $document = $this->createDocument();

        $this->assertTrue($document->generatedBy->is($this->user));
    }

    public function test_payroll_entry_generated_documents_relation_resolves()
    {
        $document = $this->createDocument();

        $this->assertTrue($this->entry->generatedDocuments->contains($document));
    }

    public function test_payroll_entry_payroll_adjustments_relation_resolves()
    {
        $adjustment = PayrollAdjustment::factory()->create([
            'company_id' => $this->company->id,
            'payroll_entry_id' => $this->entry->id,
            'created_by' => $this->user->id,
        ]);

        $this->assertTrue($this->entry->payrollAdjustments->contains($adjustment));
    }
}
