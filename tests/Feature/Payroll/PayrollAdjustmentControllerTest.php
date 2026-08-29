<?php

namespace Tests\Feature\Payroll;

use App\Models\Company;
use App\Models\Employee;
use App\Models\PayrollAdjustment;
use App\Models\PayrollConceptDefinition;
use App\Models\PayrollEntry;
use App\Models\PayrollPeriod;
use App\Models\Role;
use App\Models\User;
use App\Models\UserCompanyMembership;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * HTTP/permission/routing coverage for PayrollAdjustmentController. The
 * mechanism itself (PayrollAdjustmentService::adjustInNextPeriod) is
 * already covered exhaustively at the unit level.
 */
class PayrollAdjustmentControllerTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

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

    private function closedEntryWithOpenNextPeriodEntry(): array
    {
        $closedPeriod = PayrollPeriod::factory()->create([
            'company_id' => $this->company->id,
            'start_date' => '2026-01-01',
            'end_date' => '2026-01-15',
            'status' => 'closed',
        ]);

        $closedEntry = PayrollEntry::factory()->create([
            'company_id' => $this->company->id,
            'payroll_period_id' => $closedPeriod->id,
            'employee_id' => $this->employee->id,
            'status' => 'calculated',
            'gross_total' => 1000,
            'deductions_total' => 0,
            'net_total' => 1000,
        ]);

        $nextPeriod = PayrollPeriod::factory()->create([
            'company_id' => $this->company->id,
            'start_date' => '2026-02-01',
            'end_date' => '2026-02-15',
            'status' => 'open',
        ]);

        PayrollEntry::factory()->create([
            'company_id' => $this->company->id,
            'payroll_period_id' => $nextPeriod->id,
            'employee_id' => $this->employee->id,
            'status' => 'calculated',
            'gross_total' => 1000,
            'deductions_total' => 0,
            'net_total' => 1000,
        ]);

        $concept = PayrollConceptDefinition::factory()->create([
            'company_id' => null,
            'code' => 'BONUS_TEST',
            'type' => 'earning',
        ]);

        return [$closedEntry, $concept];
    }

    public function test_store_creates_an_adjustment_and_a_line_on_the_target_entry()
    {
        [$closedEntry, $concept] = $this->closedEntryWithOpenNextPeriodEntry();
        // COMPANY_OWNER has payroll.adjust.
        $owner = $this->userWithRole('COMPANY_OWNER', $this->company);

        $this->actingAs($owner)
            ->post(route('payroll.entries.adjustments.store', $closedEntry), [
                'concept_id' => $concept->id,
                'amount' => 50000,
                'type' => 'earning',
                'reason' => 'Bono olvidado en la liquidación original.',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $adjustment = PayrollAdjustment::query()->where('payroll_entry_id', $closedEntry->id)->first();
        $this->assertNotNull($adjustment);
        $this->assertSame('next_period', $adjustment->mechanism);
        $this->assertSame($owner->id, $adjustment->created_by);

        // The original closed entry is never touched.
        $this->assertSame('1000.00', $closedEntry->fresh()->gross_total);
    }

    public function test_store_is_denied_without_payroll_adjust_permission()
    {
        [$closedEntry, $concept] = $this->closedEntryWithOpenNextPeriodEntry();
        // ACCOUNTANT has payroll.read only.
        $accountant = $this->userWithRole('ACCOUNTANT', $this->company);

        $this->actingAs($accountant)
            ->post(route('payroll.entries.adjustments.store', $closedEntry), [
                'concept_id' => $concept->id,
                'amount' => 50000,
                'type' => 'earning',
                'reason' => 'Intento no autorizado.',
            ])
            ->assertForbidden();

        $this->assertSame(0, PayrollAdjustment::query()->count());
    }

    public function test_store_validates_required_fields()
    {
        [$closedEntry] = $this->closedEntryWithOpenNextPeriodEntry();
        $owner = $this->userWithRole('COMPANY_OWNER', $this->company);

        $this->actingAs($owner)
            ->post(route('payroll.entries.adjustments.store', $closedEntry), [])
            ->assertSessionHasErrors(['concept_id', 'amount', 'type', 'reason']);
    }

    public function test_store_rejects_a_concept_id_belonging_to_another_company()
    {
        [$closedEntry] = $this->closedEntryWithOpenNextPeriodEntry();
        $owner = $this->userWithRole('COMPANY_OWNER', $this->company);

        $otherCompany = Company::factory()->create();
        $foreignConcept = PayrollConceptDefinition::factory()->create(['company_id' => $otherCompany->id]);

        $this->actingAs($owner)
            ->post(route('payroll.entries.adjustments.store', $closedEntry), [
                'concept_id' => $foreignConcept->id,
                'amount' => 50000,
                'type' => 'earning',
                'reason' => 'Motivo cualquiera.',
            ])
            ->assertSessionHasErrors('concept_id');
    }

    /**
     * PayrollAdjustmentController does not catch
     * NoOpenNextPayrollPeriodException, matching this codebase's
     * established convention.
     */
    public function test_store_with_no_open_next_period_results_in_a_server_error()
    {
        $owner = $this->userWithRole('COMPANY_OWNER', $this->company);

        $closedPeriod = PayrollPeriod::factory()->create([
            'company_id' => $this->company->id,
            'status' => 'closed',
        ]);
        $closedEntry = PayrollEntry::factory()->create([
            'company_id' => $this->company->id,
            'payroll_period_id' => $closedPeriod->id,
            'employee_id' => $this->employee->id,
        ]);
        $concept = PayrollConceptDefinition::factory()->create(['company_id' => null]);

        $this->actingAs($owner)
            ->post(route('payroll.entries.adjustments.store', $closedEntry), [
                'concept_id' => $concept->id,
                'amount' => 10000,
                'type' => 'earning',
                'reason' => 'Sin periodo siguiente.',
            ])
            ->assertStatus(500);
    }

    public function test_a_payroll_entry_from_another_company_is_not_actionable()
    {
        $owner = $this->userWithRole('COMPANY_OWNER', $this->company);

        $otherCompany = Company::factory()->create();
        $foreignEmployee = Employee::factory()->create(['company_id' => $otherCompany->id]);
        $foreignPeriod = PayrollPeriod::factory()->create(['company_id' => $otherCompany->id, 'status' => 'closed']);
        $foreignEntry = PayrollEntry::factory()->create([
            'company_id' => $otherCompany->id,
            'payroll_period_id' => $foreignPeriod->id,
            'employee_id' => $foreignEmployee->id,
        ]);
        $foreignConcept = PayrollConceptDefinition::factory()->create(['company_id' => $otherCompany->id]);

        $client = $this->actingAs($owner);

        // See PayrollPeriodControllerTest's identical foreign-record tests:
        // a warm-up request is needed to establish the session's active
        // company before the tenant scope can reject the foreign entry.
        $client->get(route('payroll.periods.index'));

        $client->post(route('payroll.entries.adjustments.store', $foreignEntry), [
            'concept_id' => $foreignConcept->id,
            'amount' => 10000,
            'type' => 'earning',
            'reason' => 'No debería ser visible.',
        ])
            ->assertNotFound();
    }
}
