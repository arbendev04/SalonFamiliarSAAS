<?php

namespace Tests\Feature\Payroll;

use App\Models\Company;
use App\Models\Employee;
use App\Models\GeneratedDocument;
use App\Models\PayrollEntry;
use App\Models\PayrollPeriod;
use App\Models\Role;
use App\Models\User;
use App\Models\UserCompanyMembership;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * HTTP/permission/routing coverage for
 * GeneratedDocumentController::download(). PayrollReceiptService's
 * rendering/versioning logic is already covered by
 * tests/Unit/Pdf/PayrollReceiptServiceTest.php and
 * tests/Feature/Payroll/PayrollReceiptVersioningTest.php — this file only
 * proves the download endpoint's auth, tenant, and cross-reference guards.
 */
class GeneratedDocumentControllerTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake();

        $this->seed([PermissionSeeder::class, RoleSeeder::class]);

        $this->company = Company::factory()->create();
        $this->employee = Employee::factory()->create(['company_id' => $this->company->id]);
    }

    private function userWithRole(string $roleName, Company $company): User
    {
        $role = Role::query()->whereNull('company_id')->where('name', $roleName)->firstOrFail();
        $user = User::factory()->create();

        UserCompanyMembership::create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'role_id' => $role->id,
            'status' => 'active',
        ]);

        return $user;
    }

    /**
     * @return array{0: PayrollEntry, 1: GeneratedDocument}
     */
    private function closedEntryWithDocument(?Company $company = null): array
    {
        $company ??= $this->company;
        $employee = $company->is($this->company)
            ? $this->employee
            : Employee::factory()->create(['company_id' => $company->id]);

        $period = PayrollPeriod::factory()->create([
            'company_id' => $company->id,
            'status' => 'closed',
        ]);

        $entry = PayrollEntry::factory()->create([
            'company_id' => $company->id,
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
        ]);

        $storageRef = "receipts/{$company->id}/{$employee->id}/{$entry->id}/v1.pdf";
        Storage::put($storageRef, '%PDF-1.4 fixture bytes for '.$entry->id);

        $document = GeneratedDocument::factory()->create([
            'company_id' => $company->id,
            'reference_entity_type' => 'payroll_entry',
            'reference_entity_id' => $entry->id,
            'storage_ref' => $storageRef,
            'version' => 1,
        ]);

        return [$entry, $document];
    }

    public function test_download_returns_the_pdf_bytes_for_a_user_with_payroll_read_in_the_same_company()
    {
        [$entry, $document] = $this->closedEntryWithDocument();
        // ACCOUNTANT has payroll.read.
        $accountant = $this->userWithRole('ACCOUNTANT', $this->company);

        $response = $this->actingAs($accountant)
            ->get(route('payroll.entries.receipts.download', [$entry, $document]));

        $response->assertOk();
        $response->assertDownload("comprobante-{$entry->id}-v1.pdf");
        $response->assertStreamedContent(Storage::get($document->storage_ref));
    }

    public function test_download_is_denied_without_payroll_read_permission()
    {
        [$entry, $document] = $this->closedEntryWithDocument();
        // EMPLOYEE has neither payroll.read nor any payroll permission.
        $employeeUser = $this->userWithRole('EMPLOYEE', $this->company);

        $this->actingAs($employeeUser)
            ->get(route('payroll.entries.receipts.download', [$entry, $document]))
            ->assertForbidden();
    }

    public function test_download_of_another_companys_entry_is_not_found()
    {
        $otherCompany = Company::factory()->create();
        [$foreignEntry, $foreignDocument] = $this->closedEntryWithDocument($otherCompany);

        // ACCOUNTANT has payroll.read in their OWN company only.
        $accountant = $this->userWithRole('ACCOUNTANT', $this->company);
        $client = $this->actingAs($accountant);

        // Warm-up request needed to establish the session's active company
        // before the tenant scope can reject the foreign entry — same
        // pattern as PayrollAdjustmentControllerTest::
        // test_a_payroll_entry_from_another_company_is_not_actionable().
        $client->get(route('payroll.periods.index'));

        $client->get(route('payroll.entries.receipts.download', [$foreignEntry, $foreignDocument]))
            ->assertNotFound();
    }

    public function test_download_of_a_document_belonging_to_a_different_entry_is_not_found()
    {
        [$entry] = $this->closedEntryWithDocument();
        [, $otherEntryDocument] = $this->closedEntryWithDocument();

        $accountant = $this->userWithRole('ACCOUNTANT', $this->company);

        $this->actingAs($accountant)
            ->get(route('payroll.entries.receipts.download', [$entry, $otherEntryDocument]))
            ->assertNotFound();
    }

    public function test_unauthenticated_request_is_redirected_to_login()
    {
        [$entry, $document] = $this->closedEntryWithDocument();

        $response = $this->get(route('payroll.entries.receipts.download', [$entry, $document]));

        $response->assertRedirect(route('login'));
    }
}
