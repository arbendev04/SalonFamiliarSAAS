<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\EmploymentContract;
use App\Models\Position;
use App\Models\Role;
use App\Models\SalaryHistory;
use App\Models\User;
use App\Models\UserCompanyMembership;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmploymentContractTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class]);

        $ownerRole = Role::query()->whereNull('company_id')->where('name', 'COMPANY_OWNER')->firstOrFail();

        $this->company = Company::factory()->create();
        $this->owner = User::factory()->create();

        UserCompanyMembership::create([
            'user_id' => $this->owner->id,
            'company_id' => $this->company->id,
            'role_id' => $ownerRole->id,
            'status' => 'active',
        ]);

        $this->employee = Employee::factory()->create(['company_id' => $this->company->id]);
    }

    public function test_creating_a_contract_also_creates_its_initial_salary_history_row()
    {
        $this->actingAs($this->owner)->post(route('employees.contracts.store', $this->employee), [
            'contract_type' => 'indefinido',
            'start_date' => '2026-01-01',
            'base_salary' => '1500000.00',
        ])->assertRedirect();

        $contract = EmploymentContract::query()->where('employee_id', $this->employee->id)->firstOrFail();

        $this->assertSame($this->company->id, $contract->company_id);
        $this->assertSame('active', $contract->status);

        $salaryRow = SalaryHistory::query()->where('contract_id', $contract->id)->firstOrFail();
        $this->assertEquals('1500000.00', $salaryRow->base_salary);
        $this->assertSame('2026-01-01', $salaryRow->effective_from->toDateString());
    }

    public function test_a_second_contract_cannot_overlap_an_already_active_one()
    {
        EmploymentContract::factory()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'start_date' => '2025-01-01',
            'end_date' => null,
        ]);

        $response = $this->actingAs($this->owner)->post(route('employees.contracts.store', $this->employee), [
            'contract_type' => 'indefinido',
            'start_date' => '2025-06-01',
            'base_salary' => '1500000.00',
        ]);

        $response->assertSessionHasErrors('start_date');
    }

    public function test_a_non_overlapping_successor_contract_after_closing_the_previous_one_is_accepted()
    {
        EmploymentContract::factory()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'start_date' => '2025-01-01',
            'end_date' => '2025-05-31',
        ]);

        $this->actingAs($this->owner)->post(route('employees.contracts.store', $this->employee), [
            'contract_type' => 'indefinido',
            'start_date' => '2025-06-01',
            'base_salary' => '1600000.00',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(2, EmploymentContract::query()->where('employee_id', $this->employee->id)->count());
    }

    public function test_a_position_belonging_to_another_company_cannot_be_assigned_to_a_contract()
    {
        $foreignPosition = Position::factory()->create(['company_id' => Company::factory()->create()->id]);

        $response = $this->actingAs($this->owner)->post(route('employees.contracts.store', $this->employee), [
            'position_id' => $foreignPosition->id,
            'contract_type' => 'indefinido',
            'start_date' => '2026-01-01',
            'base_salary' => '1500000.00',
        ]);

        $response->assertSessionHasErrors('position_id');
    }

    public function test_the_employee_show_page_renders_contract_dates_as_plain_dates()
    {
        EmploymentContract::factory()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'start_date' => '2026-01-15',
            'end_date' => null,
        ]);

        $response = $this->actingAs($this->owner)->get(route('employees.show', $this->employee));

        $response->assertInertia(fn ($page) => $page
            ->component('employees/Show')
            ->where('contracts.0.start_date', '2026-01-15'),
        );
    }

    public function test_a_user_without_the_contracts_write_permission_is_denied()
    {
        $employeeRole = Role::query()->whereNull('company_id')->where('name', 'EMPLOYEE')->firstOrFail();

        $rankAndFile = User::factory()->create();

        UserCompanyMembership::create([
            'user_id' => $rankAndFile->id,
            'company_id' => $this->company->id,
            'role_id' => $employeeRole->id,
            'status' => 'active',
        ]);

        $this->actingAs($rankAndFile)->post(route('employees.contracts.store', $this->employee), [
            'contract_type' => 'indefinido',
            'start_date' => '2026-01-01',
            'base_salary' => '1500000.00',
        ])->assertForbidden();
    }
}
