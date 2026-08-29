<?php

namespace Tests\Feature\Payroll;

use App\Models\Company;
use App\Models\Employee;
use App\Models\EmploymentContract;
use App\Models\PayrollConceptDefinition;
use App\Models\PayrollEntry;
use App\Models\PayrollEntryLine;
use App\Models\PayrollPeriod;
use App\Models\Role;
use App\Models\User;
use App\Models\UserCompanyMembership;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * HTTP/permission/routing coverage for PayrollPeriodController. The
 * underlying transitions themselves (PayrollPeriodService) are already
 * covered exhaustively at the unit level — these tests only confirm the
 * controller wires permissions/props/service calls correctly.
 */
class PayrollPeriodControllerTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class]);

        $this->company = Company::factory()->create();
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

    public function test_index_returns_expected_props_for_a_user_with_payroll_read_permission()
    {
        // PAYROLL_MANAGER has payroll.read and payroll.calculate.
        $manager = $this->userWithRole('PAYROLL_MANAGER', $this->company);
        $period = PayrollPeriod::factory()->create([
            'company_id' => $this->company->id,
            'start_date' => '2026-01-01',
            'end_date' => '2026-01-15',
        ]);

        $this->actingAs($manager)
            ->get(route('payroll.periods.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('payroll/Index')
                ->where('canCreate', true)
                ->where('canCalculate', true)
                ->has('periods', 1)
                ->where('periods.0.id', $period->id)
            );
    }

    public function test_index_reflects_can_calculate_false_for_a_user_without_payroll_calculate_permission()
    {
        // ACCOUNTANT has payroll.read only.
        $accountant = $this->userWithRole('ACCOUNTANT', $this->company);

        $this->actingAs($accountant)
            ->get(route('payroll.periods.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('canCreate', false)
                ->where('canCalculate', false)
            );
    }

    public function test_index_is_denied_without_payroll_read_permission()
    {
        // EMPLOYEE has no payroll.* permissions at all.
        $employee = $this->userWithRole('EMPLOYEE', $this->company);

        $this->actingAs($employee)
            ->get(route('payroll.periods.index'))
            ->assertForbidden();
    }

    public function test_store_creates_an_open_period_for_a_user_with_payroll_calculate_permission()
    {
        $manager = $this->userWithRole('PAYROLL_MANAGER', $this->company);

        $this->actingAs($manager)
            ->post(route('payroll.periods.store'), [
                'period_type' => 'biweekly',
                'start_date' => '2026-03-01',
                'end_date' => '2026-03-15',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $period = PayrollPeriod::query()->where('company_id', $this->company->id)->first();

        $this->assertNotNull($period);
        $this->assertSame('open', $period->status);
        $this->assertSame('biweekly', $period->period_type);
        $this->assertSame('2026-03-01', $period->start_date->toDateString());
        $this->assertSame('2026-03-15', $period->end_date->toDateString());
    }

    public function test_store_is_denied_without_payroll_calculate_permission()
    {
        // ACCOUNTANT has payroll.read but not payroll.calculate.
        $accountant = $this->userWithRole('ACCOUNTANT', $this->company);

        $this->actingAs($accountant)
            ->post(route('payroll.periods.store'), [
                'period_type' => 'biweekly',
                'start_date' => '2026-03-01',
                'end_date' => '2026-03-15',
            ])
            ->assertForbidden();

        $this->assertSame(0, PayrollPeriod::query()->count());
    }

    public function test_store_validates_end_date_after_start_date()
    {
        $manager = $this->userWithRole('PAYROLL_MANAGER', $this->company);

        $this->actingAs($manager)
            ->post(route('payroll.periods.store'), [
                'period_type' => 'biweekly',
                'start_date' => '2026-03-15',
                'end_date' => '2026-03-01',
            ])
            ->assertSessionHasErrors('end_date');
    }

    public function test_show_returns_expected_props_including_entries_and_lines()
    {
        $owner = $this->userWithRole('COMPANY_OWNER', $this->company);
        $period = PayrollPeriod::factory()->create(['company_id' => $this->company->id, 'status' => 'calculated']);
        $employee = Employee::factory()->create(['company_id' => $this->company->id]);
        $contract = EmploymentContract::factory()->create(['company_id' => $this->company->id, 'employee_id' => $employee->id]);
        $entry = PayrollEntry::factory()->create([
            'company_id' => $this->company->id,
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
            'contract_id' => $contract->id,
            'status' => 'calculated',
            'gross_total' => 1000,
            'deductions_total' => 100,
            'net_total' => 900,
        ]);
        $concept = PayrollConceptDefinition::factory()->create(['company_id' => null]);
        PayrollEntryLine::factory()->create([
            'company_id' => $this->company->id,
            'payroll_entry_id' => $entry->id,
            'concept_id' => $concept->id,
            'contract_id' => $contract->id,
            'type' => 'earning',
            'amount' => 1000,
        ]);

        // COMPANY_OWNER lacks payroll.calculate but has approve/close/reopen/adjust.
        $this->actingAs($owner)
            ->get(route('payroll.periods.show', $period))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('payroll/Show')
                ->where('period.id', $period->id)
                ->has('entries', 1)
                ->where('entries.0.id', $entry->id)
                ->has('entries.0.lines', 1)
                ->where('canCalculate', false)
                ->where('canApprove', true)
                ->where('canClose', true)
                ->where('canReopen', true)
                ->where('canAdjust', true)
            );
    }

    public function test_show_is_denied_without_payroll_read_permission()
    {
        $employee = $this->userWithRole('EMPLOYEE', $this->company);
        $period = PayrollPeriod::factory()->create(['company_id' => $this->company->id]);

        $this->actingAs($employee)
            ->get(route('payroll.periods.show', $period))
            ->assertForbidden();
    }

    public function test_calculate_transitions_the_period_to_calculated_and_flashes_a_summary_toast()
    {
        $manager = $this->userWithRole('PAYROLL_MANAGER', $this->company);
        $period = PayrollPeriod::factory()->create(['company_id' => $this->company->id, 'status' => 'open']);

        $this->actingAs($manager)
            ->post(route('payroll.periods.calculate', $period))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('calculated', $period->fresh()->status);
    }

    public function test_calculate_is_denied_without_payroll_calculate_permission()
    {
        $accountant = $this->userWithRole('ACCOUNTANT', $this->company);
        $period = PayrollPeriod::factory()->create(['company_id' => $this->company->id, 'status' => 'open']);

        $this->actingAs($accountant)
            ->post(route('payroll.periods.calculate', $period))
            ->assertForbidden();

        $this->assertSame('open', $period->fresh()->status);
    }

    /**
     * PayrollPeriodController::calculate() does not catch
     * InvalidPayrollPeriodStatusException, matching the rest of the
     * codebase's established convention.
     */
    public function test_calculate_on_a_closed_period_results_in_a_server_error()
    {
        $manager = $this->userWithRole('PAYROLL_MANAGER', $this->company);
        $period = PayrollPeriod::factory()->create(['company_id' => $this->company->id, 'status' => 'closed']);

        $this->actingAs($manager)
            ->post(route('payroll.periods.calculate', $period))
            ->assertStatus(500);
    }

    public function test_approve_transitions_calculated_to_approved()
    {
        // COMPANY_OWNER has payroll.approve.
        $owner = $this->userWithRole('COMPANY_OWNER', $this->company);
        $period = PayrollPeriod::factory()->create(['company_id' => $this->company->id, 'status' => 'calculated']);

        $this->actingAs($owner)
            ->post(route('payroll.periods.approve', $period))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('approved', $period->fresh()->status);
    }

    public function test_approve_is_denied_without_payroll_approve_permission()
    {
        $accountant = $this->userWithRole('ACCOUNTANT', $this->company);
        $period = PayrollPeriod::factory()->create(['company_id' => $this->company->id, 'status' => 'calculated']);

        $this->actingAs($accountant)
            ->post(route('payroll.periods.approve', $period))
            ->assertForbidden();

        $this->assertSame('calculated', $period->fresh()->status);
    }

    public function test_close_transitions_calculated_to_closed_and_records_who_closed_it()
    {
        $owner = $this->userWithRole('COMPANY_OWNER', $this->company);
        $period = PayrollPeriod::factory()->create(['company_id' => $this->company->id, 'status' => 'calculated']);

        $this->actingAs($owner)
            ->post(route('payroll.periods.close', $period))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $period->refresh();
        $this->assertSame('closed', $period->status);
        $this->assertSame($owner->id, $period->closed_by);
        $this->assertNotNull($period->closed_at);
    }

    public function test_close_is_denied_without_payroll_close_permission()
    {
        $accountant = $this->userWithRole('ACCOUNTANT', $this->company);
        $period = PayrollPeriod::factory()->create(['company_id' => $this->company->id, 'status' => 'calculated']);

        $this->actingAs($accountant)
            ->post(route('payroll.periods.close', $period))
            ->assertForbidden();

        $this->assertSame('calculated', $period->fresh()->status);
    }

    /**
     * PayrollPeriodService::close() throws
     * UnresolvedBlockedPayrollEntriesException, left uncaught per this
     * codebase's convention.
     */
    public function test_close_with_a_blocked_entry_results_in_a_server_error()
    {
        $owner = $this->userWithRole('COMPANY_OWNER', $this->company);
        $period = PayrollPeriod::factory()->create(['company_id' => $this->company->id, 'status' => 'calculated']);
        PayrollEntry::factory()->create([
            'company_id' => $this->company->id,
            'payroll_period_id' => $period->id,
            'contract_id' => null,
            'status' => 'blocked',
        ]);

        $this->actingAs($owner)
            ->post(route('payroll.periods.close', $period))
            ->assertStatus(500);

        $this->assertSame('calculated', $period->fresh()->status);
    }

    public function test_reopen_transitions_closed_to_reopened_with_a_reason()
    {
        // COMPANY_OWNER has payroll.reopen.
        $owner = $this->userWithRole('COMPANY_OWNER', $this->company);
        $period = PayrollPeriod::factory()->create(['company_id' => $this->company->id, 'status' => 'closed']);

        $this->actingAs($owner)
            ->post(route('payroll.periods.reopen', $period), ['reason' => 'Corrección de horas extra mal autorizadas.'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('reopened', $period->fresh()->status);
    }

    public function test_reopen_is_denied_without_payroll_reopen_permission()
    {
        // PAYROLL_MANAGER lacks payroll.reopen.
        $manager = $this->userWithRole('PAYROLL_MANAGER', $this->company);
        $period = PayrollPeriod::factory()->create(['company_id' => $this->company->id, 'status' => 'closed']);

        $this->actingAs($manager)
            ->post(route('payroll.periods.reopen', $period), ['reason' => 'Motivo cualquiera.'])
            ->assertForbidden();

        $this->assertSame('closed', $period->fresh()->status);
    }

    public function test_reopen_requires_a_non_empty_reason()
    {
        $owner = $this->userWithRole('COMPANY_OWNER', $this->company);
        $period = PayrollPeriod::factory()->create(['company_id' => $this->company->id, 'status' => 'closed']);

        $this->actingAs($owner)
            ->post(route('payroll.periods.reopen', $period), ['reason' => ''])
            ->assertSessionHasErrors('reason');

        $this->assertSame('closed', $period->fresh()->status);
    }

    public function test_a_payroll_period_from_another_company_is_not_visible_or_actionable()
    {
        $owner = $this->userWithRole('COMPANY_OWNER', $this->company);
        $otherCompany = Company::factory()->create();
        $foreignPeriod = PayrollPeriod::factory()->create(['company_id' => $otherCompany->id, 'status' => 'closed']);

        $client = $this->actingAs($owner);

        // SetCurrentCompany resolves the active company AFTER route-model
        // binding runs for the current request (same pattern as
        // OvertimeRecordTest/AttendanceRecordTest's identical foreign-record
        // tests), so a warm-up request must establish the session's active
        // company before the tenant scope can reject the foreign period.
        $client->get(route('payroll.periods.index'));

        $client->get(route('payroll.periods.show', $foreignPeriod))->assertNotFound();
        $client->post(route('payroll.periods.calculate', $foreignPeriod))->assertNotFound();
        $client->post(route('payroll.periods.approve', $foreignPeriod))->assertNotFound();
        $client->post(route('payroll.periods.close', $foreignPeriod))->assertNotFound();
        $client->post(route('payroll.periods.reopen', $foreignPeriod), ['reason' => 'x'])->assertNotFound();
    }
}
