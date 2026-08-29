<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\LeaveRecord;
use App\Models\LeaveType;
use App\Models\NoveltyType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserCompanyMembership;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeaveRecordTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class]);

        $this->company = Company::factory()->create();
        $this->owner = $this->userWithRole('COMPANY_OWNER', $this->company);
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
     * Every seeded role that has `leave.create` also has `leave.approve`
     * (RoleSeeder), so a custom role isolating "can request but not
     * auto-approve" is needed to prove ADR-032's derivation is by
     * permission code, not by role name — mirrors
     * tests/Unit/LeaveRecordServiceTest.php::userWithOnlyLeaveCreatePermission.
     */
    private function userWithOnlyLeaveCreatePermission(Company $company): User
    {
        $role = Role::create(['company_id' => null, 'name' => 'LEAVE_REQUESTER_ONLY', 'is_system' => false]);
        $role->permissions()->sync([
            Permission::query()->where('code', 'leave.create')->firstOrFail()->id,
        ]);

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
     * A platform-default leave_type/novelty_type pair sharing `code`, the
     * correlation key LeaveRecordService::generateNoveltyAndAbsence() uses.
     * Mirrors tests/Unit/LeaveRecordServiceTest.php::correlatedCatalogPair.
     */
    private function correlatedCatalogPair(string $code): LeaveType
    {
        $leaveType = LeaveType::factory()->create(['company_id' => null, 'code' => $code]);

        NoveltyType::factory()->create([
            'company_id' => null,
            'code' => $code,
            'affects_time_calc' => true,
        ]);

        return $leaveType;
    }

    public function test_index_returns_expected_props_for_a_user_with_leave_create_permission()
    {
        $requester = $this->userWithOnlyLeaveCreatePermission($this->company);
        $leaveType = $this->correlatedCatalogPair('VACACIONES');

        $this->actingAs($requester)
            ->get(route('employees.leave-records.index', $this->employee))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('employees/LeaveRecords')
                ->where('employee.id', $this->employee->id)
                ->where('canCreate', true)
                ->where('canApprove', false)
                ->has('records')
                ->has('leaveTypes', 1)
                ->where('leaveTypes.0.id', $leaveType->id)
            );
    }

    public function test_index_returns_expected_props_for_a_user_with_leave_approve_permission()
    {
        $this->actingAs($this->owner)
            ->get(route('employees.leave-records.index', $this->employee))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('employees/LeaveRecords')
                ->where('canCreate', true)
                ->where('canApprove', true)
            );
    }

    public function test_index_is_denied_for_a_user_with_neither_permission()
    {
        $accountant = $this->userWithRole('ACCOUNTANT', $this->company);

        $this->actingAs($accountant)
            ->get(route('employees.leave-records.index', $this->employee))
            ->assertForbidden();
    }

    public function test_store_by_a_user_with_leave_approve_permission_auto_approves()
    {
        $leaveType = $this->correlatedCatalogPair('VACACIONES');

        $this->actingAs($this->owner)->post(route('employees.leave-records.store', $this->employee), [
            'leave_type_id' => $leaveType->id,
            'date_from' => '2026-03-02',
            'date_to' => '2026-03-04',
            'reason' => 'Vacaciones programadas.',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $record = LeaveRecord::query()->where('employee_id', $this->employee->id)->firstOrFail();
        $this->assertSame('approved', $record->status);
        $this->assertSame($this->owner->id, $record->approved_by);
    }

    public function test_store_by_a_user_with_only_leave_create_permission_stays_pending()
    {
        $requester = $this->userWithOnlyLeaveCreatePermission($this->company);
        $leaveType = $this->correlatedCatalogPair('PERMISO');

        $this->actingAs($requester)->post(route('employees.leave-records.store', $this->employee), [
            'leave_type_id' => $leaveType->id,
            'date_from' => '2026-03-02',
            'date_to' => '2026-03-02',
            'reason' => 'Permiso personal.',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $record = LeaveRecord::query()->where('employee_id', $this->employee->id)->firstOrFail();
        $this->assertSame('pending', $record->status);
        $this->assertNull($record->approved_by);
    }

    public function test_store_is_denied_without_leave_create_permission()
    {
        $accountant = $this->userWithRole('ACCOUNTANT', $this->company);
        $leaveType = $this->correlatedCatalogPair('VACACIONES');

        $this->actingAs($accountant)->post(route('employees.leave-records.store', $this->employee), [
            'leave_type_id' => $leaveType->id,
            'date_from' => '2026-03-02',
            'date_to' => '2026-03-02',
            'reason' => 'Sin permiso.',
        ])->assertForbidden();

        $this->assertSame(0, LeaveRecord::query()->count());
    }

    public function test_approve_and_reject_are_denied_without_leave_approve_permission()
    {
        $requester = $this->userWithOnlyLeaveCreatePermission($this->company);

        $record = LeaveRecord::factory()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'status' => 'pending',
        ]);

        $this->actingAs($requester)
            ->post(route('leave-records.approve', $record))
            ->assertForbidden();

        $this->actingAs($requester)
            ->post(route('leave-records.reject', $record))
            ->assertForbidden();

        $this->assertSame('pending', $record->fresh()->status);
    }

    /**
     * LeaveRecordController does not catch InvalidLeaveRecordStatusException
     * — matching AttendanceAdjustmentController's established convention of
     * letting the domain exception propagate to Laravel's default error
     * handler rather than converting it to a flashed error toast. See
     * AttendanceAdjustmentTest::test_approve_only_operates_on_a_pending_adjustment,
     * which asserts the same shape at the service layer; this test proves
     * the HTTP layer inherits the same (uncaught -> 500) behavior since the
     * controller adds no try/catch of its own.
     */
    public function test_approve_on_an_already_approved_record_results_in_a_server_error()
    {
        $record = LeaveRecord::factory()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'status' => 'approved',
            'approved_by' => $this->owner->id,
        ]);

        $this->actingAs($this->owner)
            ->post(route('leave-records.approve', $record))
            ->assertStatus(500);
    }

    public function test_a_leave_record_from_another_company_is_not_visible_or_actionable()
    {
        $otherCompany = Company::factory()->create();
        $foreignEmployee = Employee::factory()->create(['company_id' => $otherCompany->id]);

        $foreignRecord = LeaveRecord::factory()->create([
            'company_id' => $otherCompany->id,
            'employee_id' => $foreignEmployee->id,
            'status' => 'pending',
        ]);

        $foreignLeaveType = $this->correlatedCatalogPair('VACACIONES_AJENAS');

        // The active company is resolved onto the session by
        // SetCurrentCompany, which runs after route-model-binding
        // middleware (see AttendanceAdjustmentTest for the same pattern),
        // so a prior request must establish that session state first.
        $client = $this->actingAs($this->owner);
        $client->get(route('employees.leave-records.index', $this->employee));

        $client->get(route('employees.leave-records.index', $foreignEmployee))
            ->assertNotFound();

        $client->post(route('employees.leave-records.store', $foreignEmployee), [
            'leave_type_id' => $foreignLeaveType->id,
            'date_from' => '2026-03-02',
            'date_to' => '2026-03-02',
            'reason' => 'No debería poder crear sobre un empleado ajeno.',
        ])->assertNotFound();

        $client->post(route('leave-records.approve', $foreignRecord))
            ->assertNotFound();

        $client->post(route('leave-records.reject', $foreignRecord))
            ->assertNotFound();
    }
}
