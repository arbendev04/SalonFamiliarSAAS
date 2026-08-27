<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use App\Models\UserCompanyMembership;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeeTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Company $companyA;

    private Company $companyB;

    private User $userA;

    private User $userB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class]);

        $ownerRole = Role::query()->whereNull('company_id')->where('name', 'COMPANY_OWNER')->firstOrFail();

        $this->companyA = Company::factory()->create(['legal_name' => 'Panadería A']);
        $this->companyB = Company::factory()->create(['legal_name' => 'Panadería B']);

        $this->userA = User::factory()->create();
        $this->userB = User::factory()->create();

        UserCompanyMembership::create([
            'user_id' => $this->userA->id,
            'company_id' => $this->companyA->id,
            'role_id' => $ownerRole->id,
            'status' => 'active',
        ]);

        UserCompanyMembership::create([
            'user_id' => $this->userB->id,
            'company_id' => $this->companyB->id,
            'role_id' => $ownerRole->id,
            'status' => 'active',
        ]);

        Employee::factory()->create(['company_id' => $this->companyA->id, 'full_name' => 'Empleado A']);
        Employee::factory()->create(['company_id' => $this->companyB->id, 'full_name' => 'Empleado B']);
    }

    public function test_user_only_sees_employees_from_their_own_company()
    {
        $response = $this->actingAs($this->userA)->get(route('employees.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('employees/Index')
            ->has('employees', 1)
            ->where('employees.0.full_name', 'Empleado A'),
        );
    }

    public function test_the_other_company_sees_only_its_own_employee()
    {
        $response = $this->actingAs($this->userB)->get(route('employees.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('employees/Index')
            ->has('employees', 1)
            ->where('employees.0.full_name', 'Empleado B'),
        );
    }

    public function test_creating_an_employee_is_scoped_to_the_authenticated_users_company()
    {
        $this->actingAs($this->userA)->post(route('employees.store'), [
            'full_name' => 'Nuevo Empleado',
            'document_type' => 'CC',
            'national_id' => '999999999',
            'hire_date' => now()->toDateString(),
        ])->assertRedirect();

        $employee = Employee::query()->where('full_name', 'Nuevo Empleado')->firstOrFail();

        $this->assertSame($this->companyA->id, $employee->company_id);
    }

    public function test_a_branch_belonging_to_another_company_cannot_be_assigned_to_an_employee()
    {
        $foreignBranch = Branch::factory()->create(['company_id' => $this->companyB->id]);

        $response = $this->actingAs($this->userA)->post(route('employees.store'), [
            'full_name' => 'Empleado Cruzado',
            'document_type' => 'CC',
            'national_id' => '888888888',
            'hire_date' => now()->toDateString(),
            'branch_id' => $foreignBranch->id,
        ]);

        $response->assertSessionHasErrors('branch_id');
    }

    public function test_a_user_without_the_employees_read_permission_is_denied()
    {
        $employeeRole = Role::query()->whereNull('company_id')->where('name', 'EMPLOYEE')->firstOrFail();

        $rankAndFile = User::factory()->create();

        UserCompanyMembership::create([
            'user_id' => $rankAndFile->id,
            'company_id' => $this->companyA->id,
            'role_id' => $employeeRole->id,
            'status' => 'active',
        ]);

        $this->actingAs($rankAndFile)
            ->get(route('employees.index'))
            ->assertForbidden();
    }
}
