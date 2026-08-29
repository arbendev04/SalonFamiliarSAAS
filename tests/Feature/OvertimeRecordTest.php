<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\OvertimeRecord;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Shift;
use App\Models\User;
use App\Models\UserCompanyMembership;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OvertimeRecordTest extends TestCase
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
     * Every seeded role that has `overtime.authorize` also has
     * `overtime.request` (RoleSeeder) — a custom role isolating "can
     * authorize but not request" is needed to prove the index's canRequest/
     * canAuthorize props are derived by permission code, not by role name.
     * Mirrors LeaveRecordTest::userWithOnlyLeaveCreatePermission.
     */
    private function userWithOnlyOvertimeAuthorizePermission(Company $company): User
    {
        $role = Role::create(['company_id' => null, 'name' => 'OVERTIME_AUTHORIZER_ONLY', 'is_system' => false]);
        $role->permissions()->sync([
            Permission::query()->where('code', 'overtime.authorize')->firstOrFail()->id,
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
     * `detected` rows are never created by any user action in this app yet
     * (TimeCalculationEngine wiring is a later commit) — seeded directly via
     * the factory, same convention as OvertimeRecordServiceTest. Each call
     * creates a fresh Shift: the unique constraint is on
     * (employee_id, shift_id).
     */
    private function detectedRecord(): OvertimeRecord
    {
        return OvertimeRecord::factory()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'shift_id' => Shift::factory()->create(['company_id' => $this->company->id])->id,
            'detected_minutes' => 45,
        ]);
    }

    public function test_index_returns_expected_props_for_a_user_with_overtime_request_permission()
    {
        // EMPLOYEE is seeded with overtime.request only (RoleSeeder).
        $requester = $this->userWithRole('EMPLOYEE', $this->company);
        $record = $this->detectedRecord();

        $this->actingAs($requester)
            ->get(route('employees.overtime-records.index', $this->employee))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('employees/OvertimeRecords')
                ->where('employee.id', $this->employee->id)
                ->where('canRequest', true)
                ->where('canAuthorize', false)
                ->has('records', 1)
                ->where('records.0.id', $record->id)
            );
    }

    public function test_index_returns_expected_props_for_a_user_with_overtime_authorize_permission()
    {
        $authorizer = $this->userWithOnlyOvertimeAuthorizePermission($this->company);
        $this->detectedRecord();

        $this->actingAs($authorizer)
            ->get(route('employees.overtime-records.index', $this->employee))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('employees/OvertimeRecords')
                ->where('canRequest', false)
                ->where('canAuthorize', true)
                ->has('records', 1)
            );
    }

    public function test_index_is_denied_for_a_user_with_neither_permission()
    {
        $accountant = $this->userWithRole('ACCOUNTANT', $this->company);

        $this->actingAs($accountant)
            ->get(route('employees.overtime-records.index', $this->employee))
            ->assertForbidden();
    }

    public function test_request_transitions_detected_to_requested_and_sets_requested_minutes()
    {
        $record = $this->detectedRecord();

        $this->actingAs($this->owner)
            ->post(route('overtime-records.request', $record), ['requested_minutes' => 40])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $record->refresh();
        $this->assertSame('requested', $record->status);
        $this->assertSame(40, $record->requested_minutes);
    }

    public function test_request_is_denied_without_overtime_request_permission()
    {
        $accountant = $this->userWithRole('ACCOUNTANT', $this->company);
        $record = $this->detectedRecord();

        $this->actingAs($accountant)
            ->post(route('overtime-records.request', $record), ['requested_minutes' => 40])
            ->assertForbidden();

        $this->assertSame('detected', $record->fresh()->status);
    }

    public function test_authorize_transitions_requested_to_authorized()
    {
        $record = $this->detectedRecord();
        $record->update(['status' => 'requested', 'requested_minutes' => 40]);

        $this->actingAs($this->owner)
            ->post(route('overtime-records.authorize', $record), ['authorized_minutes' => 35])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $record->refresh();
        $this->assertSame('authorized', $record->status);
        $this->assertSame(35, $record->authorized_minutes);
    }

    public function test_authorize_is_denied_without_overtime_authorize_permission()
    {
        // EMPLOYEE only has overtime.request.
        $requester = $this->userWithRole('EMPLOYEE', $this->company);
        $record = $this->detectedRecord();
        $record->update(['status' => 'requested', 'requested_minutes' => 40]);

        $this->actingAs($requester)
            ->post(route('overtime-records.authorize', $record), ['authorized_minutes' => 35])
            ->assertForbidden();

        $this->assertSame('requested', $record->fresh()->status);
    }

    public function test_reject_transitions_requested_to_rejected()
    {
        $record = $this->detectedRecord();
        $record->update(['status' => 'requested', 'requested_minutes' => 40]);

        $this->actingAs($this->owner)
            ->post(route('overtime-records.reject', $record))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('rejected', $record->fresh()->status);
    }

    public function test_reject_is_denied_without_overtime_authorize_permission()
    {
        $requester = $this->userWithRole('EMPLOYEE', $this->company);
        $record = $this->detectedRecord();
        $record->update(['status' => 'requested', 'requested_minutes' => 40]);

        $this->actingAs($requester)
            ->post(route('overtime-records.reject', $record))
            ->assertForbidden();

        $this->assertSame('requested', $record->fresh()->status);
    }

    public function test_mark_paid_transitions_authorized_to_paid()
    {
        $record = $this->detectedRecord();
        $record->update(['status' => 'authorized', 'requested_minutes' => 40, 'authorized_minutes' => 35]);

        $this->actingAs($this->owner)
            ->post(route('overtime-records.mark-paid', $record))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('paid', $record->fresh()->status);
    }

    public function test_mark_paid_is_denied_without_overtime_mark_paid_permission()
    {
        $authorizer = $this->userWithOnlyOvertimeAuthorizePermission($this->company);
        $record = $this->detectedRecord();
        $record->update(['status' => 'authorized', 'requested_minutes' => 40, 'authorized_minutes' => 35]);

        $this->actingAs($authorizer)
            ->post(route('overtime-records.mark-paid', $record))
            ->assertForbidden();

        $this->assertSame('authorized', $record->fresh()->status);
    }

    /**
     * OvertimeRecordController does not catch
     * InvalidOvertimeRecordStatusException — matching
     * LeaveRecordController/AttendanceAdjustmentController's established
     * convention of letting the domain exception propagate to Laravel's
     * default error handler rather than converting it to a flashed error
     * toast. Asserted explicitly for both a `detected` and a `requested`
     * (not yet authorized) record, mirroring
     * OvertimeRecordServiceTest::test_overtime_cannot_be_marked_paid_without_authorization.
     */
    public function test_mark_paid_on_a_detected_record_results_in_a_server_error()
    {
        $record = $this->detectedRecord();

        $this->actingAs($this->owner)
            ->post(route('overtime-records.mark-paid', $record))
            ->assertStatus(500);
    }

    public function test_mark_paid_on_a_requested_record_results_in_a_server_error()
    {
        $record = $this->detectedRecord();
        $record->update(['status' => 'requested', 'requested_minutes' => 40]);

        $this->actingAs($this->owner)
            ->post(route('overtime-records.mark-paid', $record))
            ->assertStatus(500);
    }

    public function test_an_overtime_record_from_another_company_is_not_visible_or_actionable()
    {
        $otherCompany = Company::factory()->create();
        $foreignEmployee = Employee::factory()->create(['company_id' => $otherCompany->id]);
        $foreignRecord = OvertimeRecord::factory()->create([
            'company_id' => $otherCompany->id,
            'employee_id' => $foreignEmployee->id,
            'shift_id' => Shift::factory()->create(['company_id' => $otherCompany->id])->id,
        ]);

        // The active company is resolved onto the session by
        // SetCurrentCompany, which runs after route-model-binding
        // middleware (see LeaveRecordTest/AttendanceAdjustmentTest for the
        // same pattern), so a prior request must establish that session
        // state first.
        $client = $this->actingAs($this->owner);
        $client->get(route('employees.overtime-records.index', $this->employee));

        $client->get(route('employees.overtime-records.index', $foreignEmployee))
            ->assertNotFound();

        $client->post(route('overtime-records.request', $foreignRecord), ['requested_minutes' => 40])
            ->assertNotFound();

        $client->post(route('overtime-records.authorize', $foreignRecord), ['authorized_minutes' => 35])
            ->assertNotFound();

        $client->post(route('overtime-records.reject', $foreignRecord))
            ->assertNotFound();

        $client->post(route('overtime-records.mark-paid', $foreignRecord))
            ->assertNotFound();
    }
}
