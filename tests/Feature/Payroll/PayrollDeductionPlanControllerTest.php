<?php

namespace Tests\Feature\Payroll;

use App\Models\Company;
use App\Models\Employee;
use App\Models\PayrollConceptDefinition;
use App\Models\PayrollDeductionPlan;
use App\Models\Role;
use App\Models\User;
use App\Models\UserCompanyMembership;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayrollDeductionPlanControllerTest extends TestCase
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

    public function test_index_returns_expected_props_for_a_user_with_payroll_read_permission()
    {
        $accountant = $this->userWithRole('ACCOUNTANT', $this->company);
        $concept = PayrollConceptDefinition::factory()->create(['company_id' => $this->company->id]);
        $plan = PayrollDeductionPlan::factory()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'concept_id' => $concept->id,
            'total_amount' => 300000,
            'installments' => 3,
            'installment_amount' => 100000,
            'remaining' => 300000,
        ]);

        $this->actingAs($accountant)
            ->get(route('employees.deduction-plans.index', $this->employee))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('employees/DeductionPlans')
                ->where('employee.id', $this->employee->id)
                ->has('plans', 1)
                ->where('plans.0.id', $plan->id)
                ->where('canManage', false)
            );
    }

    public function test_index_is_denied_without_payroll_read_permission()
    {
        $rankAndFile = $this->userWithRole('EMPLOYEE', $this->company);

        $this->actingAs($rankAndFile)
            ->get(route('employees.deduction-plans.index', $this->employee))
            ->assertForbidden();
    }

    public function test_store_creates_a_plan_with_installment_amount_computed_once()
    {
        // COMPANY_OWNER has payroll.adjust.
        $owner = $this->userWithRole('COMPANY_OWNER', $this->company);
        $concept = PayrollConceptDefinition::factory()->create(['company_id' => null]);

        $this->actingAs($owner)
            ->post(route('employees.deduction-plans.store', $this->employee), [
                'concept_id' => $concept->id,
                'total_amount' => 300000,
                'installments' => 3,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $plan = PayrollDeductionPlan::query()->where('employee_id', $this->employee->id)->first();

        $this->assertNotNull($plan);
        $this->assertSame('300000.00', $plan->total_amount);
        $this->assertSame(3, $plan->installments);
        $this->assertSame('100000.00', $plan->installment_amount);
        $this->assertSame('300000.00', $plan->remaining);
    }

    public function test_store_is_denied_without_payroll_adjust_permission()
    {
        // ACCOUNTANT has payroll.read only.
        $accountant = $this->userWithRole('ACCOUNTANT', $this->company);
        $concept = PayrollConceptDefinition::factory()->create(['company_id' => null]);

        $this->actingAs($accountant)
            ->post(route('employees.deduction-plans.store', $this->employee), [
                'concept_id' => $concept->id,
                'total_amount' => 300000,
                'installments' => 3,
            ])
            ->assertForbidden();

        $this->assertSame(0, PayrollDeductionPlan::query()->count());
    }

    public function test_store_validates_required_fields()
    {
        $owner = $this->userWithRole('COMPANY_OWNER', $this->company);

        $this->actingAs($owner)
            ->post(route('employees.deduction-plans.store', $this->employee), [])
            ->assertSessionHasErrors(['concept_id', 'total_amount', 'installments']);
    }

    public function test_an_employee_from_another_company_is_not_visible_or_actionable()
    {
        $owner = $this->userWithRole('COMPANY_OWNER', $this->company);
        $foreignEmployee = Employee::factory()->create(['company_id' => Company::factory()->create()->id]);

        $client = $this->actingAs($owner);

        // See PayrollPeriodControllerTest's identical foreign-record tests:
        // a warm-up request is needed to establish the session's active
        // company before the tenant scope can reject the foreign employee.
        $client->get(route('employees.deduction-plans.index', $this->employee));

        $client->get(route('employees.deduction-plans.index', $foreignEmployee))->assertNotFound();

        $concept = PayrollConceptDefinition::factory()->create(['company_id' => null]);
        $client->post(route('employees.deduction-plans.store', $foreignEmployee), [
            'concept_id' => $concept->id,
            'total_amount' => 100000,
            'installments' => 2,
        ])->assertNotFound();
    }
}
