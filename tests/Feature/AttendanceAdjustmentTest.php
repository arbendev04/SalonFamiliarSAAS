<?php

namespace Tests\Feature;

use App\Exceptions\InvalidAttendanceAdjustmentStatusException;
use App\Models\AttendanceAdjustment;
use App\Models\AttendanceEvent;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use App\Models\UserCompanyMembership;
use App\Services\Attendance\AttendanceAdjustmentService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendanceAdjustmentTest extends TestCase
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

    public function test_creating_without_reason_is_rejected()
    {
        $this->actingAs($this->owner)->post(route('employees.attendance.adjustments.store', $this->employee), [
            'type' => 'add',
            'corrected_value' => ['event_type' => 'clock_in', 'event_datetime' => '2026-02-10 08:00:00'],
        ])->assertSessionHasErrors('reason');

        $this->assertSame(0, AttendanceAdjustment::query()->count());
    }

    public function test_type_modify_creates_the_adjustment_and_leaves_the_original_event_untouched()
    {
        $event = AttendanceEvent::factory()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'event_type' => 'clock_in',
            'event_datetime' => '2026-02-10 08:00:00',
        ]);

        $this->actingAs($this->owner)->post(route('employees.attendance.adjustments.store', $this->employee), [
            'type' => 'modify',
            'original_event_id' => $event->id,
            'corrected_value' => ['event_datetime' => '2026-02-10 08:05:00'],
            'reason' => 'El reloj del dispositivo estaba mal configurado.',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $adjustment = AttendanceAdjustment::query()->where('employee_id', $this->employee->id)->firstOrFail();
        $this->assertSame('modify', $adjustment->type);
        $this->assertSame($event->id, $adjustment->original_event_id);
        $this->assertSame('approved', $adjustment->status);

        $this->assertDatabaseHas('attendance_events', [
            'id' => $event->id,
            'event_type' => 'clock_in',
            'event_datetime' => '2026-02-10 08:00:00',
        ]);
    }

    public function test_type_add_auto_approved_inserts_an_attendance_event_linked_to_the_adjustment()
    {
        $this->actingAs($this->owner)->post(route('employees.attendance.adjustments.store', $this->employee), [
            'type' => 'add',
            'corrected_value' => ['event_type' => 'clock_out', 'event_datetime' => '2026-02-10 17:05:00'],
            'reason' => 'Olvidó marcar la salida.',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $adjustment = AttendanceAdjustment::query()->where('employee_id', $this->employee->id)->firstOrFail();
        $this->assertSame('approved', $adjustment->status);
        $this->assertSame($this->owner->id, $adjustment->approved_by);

        $event = AttendanceEvent::query()->where('employee_id', $this->employee->id)->firstOrFail();
        $this->assertSame('clock_out', $event->event_type);
        $this->assertSame('manual', $event->source);
        $this->assertSame($adjustment->id, $event->metadata['created_from_adjustment_id'] ?? null);
    }

    public function test_type_add_requested_without_approval_permission_stays_pending_until_approved()
    {
        $supervisor = $this->userWithRole('SUPERVISOR', $this->company);

        $this->actingAs($supervisor)->post(route('employees.attendance.adjustments.store', $this->employee), [
            'type' => 'add',
            'corrected_value' => ['event_type' => 'clock_out', 'event_datetime' => '2026-02-10 17:05:00'],
            'reason' => 'Olvidó marcar la salida.',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $adjustment = AttendanceAdjustment::query()->where('employee_id', $this->employee->id)->firstOrFail();
        $this->assertSame('pending', $adjustment->status);
        $this->assertNull($adjustment->approved_by);
        $this->assertSame(0, AttendanceEvent::query()->where('employee_id', $this->employee->id)->count());

        $this->actingAs($this->owner)
            ->post(route('attendance.adjustments.approve', $adjustment))
            ->assertRedirect()->assertSessionHasNoErrors();

        $adjustment->refresh();
        $this->assertSame('approved', $adjustment->status);
        $this->assertSame($this->owner->id, $adjustment->approved_by);

        $event = AttendanceEvent::query()->where('employee_id', $this->employee->id)->firstOrFail();
        $this->assertSame('clock_out', $event->event_type);
        $this->assertSame($adjustment->id, $event->metadata['created_from_adjustment_id'] ?? null);
    }

    public function test_type_invalidate_creates_the_adjustment_without_mutating_or_deleting_the_original_event()
    {
        $event = AttendanceEvent::factory()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'event_type' => 'clock_in',
            'event_datetime' => '2026-02-10 08:00:00',
        ]);

        $this->actingAs($this->owner)->post(route('employees.attendance.adjustments.store', $this->employee), [
            'type' => 'invalidate',
            'original_event_id' => $event->id,
            'corrected_value' => ['reason_code' => 'not_a_real_marking'],
            'reason' => 'Marcación accidental que no corresponde a este empleado.',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $adjustment = AttendanceAdjustment::query()->where('employee_id', $this->employee->id)->firstOrFail();
        $this->assertSame('invalidate', $adjustment->type);
        $this->assertSame($event->id, $adjustment->original_event_id);

        $this->assertDatabaseHas('attendance_events', ['id' => $event->id]);
        $this->assertSame(1, AttendanceEvent::query()->where('employee_id', $this->employee->id)->count());
    }

    public function test_auto_approval_is_derived_from_the_approve_permission_not_a_hardcoded_role_list()
    {
        $hrManager = $this->userWithRole('HR_MANAGER', $this->company);
        $supervisor = $this->userWithRole('SUPERVISOR', $this->company);

        $this->actingAs($hrManager)->post(route('employees.attendance.adjustments.store', $this->employee), [
            'type' => 'add',
            'corrected_value' => ['event_type' => 'clock_in', 'event_datetime' => '2026-02-10 08:00:00'],
            'reason' => 'HR_MANAGER puede auto-aprobar su propio ajuste.',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(
            'approved',
            AttendanceAdjustment::query()->where('reason', 'HR_MANAGER puede auto-aprobar su propio ajuste.')->firstOrFail()->status,
        );

        $this->actingAs($supervisor)->post(route('employees.attendance.adjustments.store', $this->employee), [
            'type' => 'add',
            'corrected_value' => ['event_type' => 'clock_out', 'event_datetime' => '2026-02-10 17:00:00'],
            'reason' => 'SUPERVISOR solo puede solicitar, no auto-aprobar.',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(
            'pending',
            AttendanceAdjustment::query()->where('reason', 'SUPERVISOR solo puede solicitar, no auto-aprobar.')->firstOrFail()->status,
        );
    }

    public function test_approve_only_operates_on_a_pending_adjustment()
    {
        $adjustment = AttendanceAdjustment::factory()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'requested_by' => $this->owner->id,
            'status' => 'approved',
        ]);

        $this->expectException(InvalidAttendanceAdjustmentStatusException::class);

        app(AttendanceAdjustmentService::class)->approve($adjustment, $this->owner);
    }

    public function test_reject_only_operates_on_a_pending_adjustment()
    {
        $adjustment = AttendanceAdjustment::factory()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'requested_by' => $this->owner->id,
            'status' => 'rejected',
        ]);

        $this->expectException(InvalidAttendanceAdjustmentStatusException::class);

        app(AttendanceAdjustmentService::class)->reject($adjustment, $this->owner);
    }

    public function test_exactly_one_audit_log_row_is_created_per_action()
    {
        $supervisor = $this->userWithRole('SUPERVISOR', $this->company);

        $this->actingAs($supervisor)->post(route('employees.attendance.adjustments.store', $this->employee), [
            'type' => 'add',
            'corrected_value' => ['event_type' => 'clock_in', 'event_datetime' => '2026-02-10 08:00:00'],
            'reason' => 'Falta marcación de entrada.',
        ]);

        $adjustment = AttendanceAdjustment::query()->where('employee_id', $this->employee->id)->firstOrFail();

        $this->assertSame(
            1,
            AuditLog::query()->where('entity_id', $adjustment->id)->where('action', 'attendance_adjustment.created')->count(),
        );

        $this->actingAs($this->owner)->post(route('attendance.adjustments.approve', $adjustment));

        $this->assertSame(
            1,
            AuditLog::query()->where('entity_id', $adjustment->id)->where('action', 'attendance_adjustment.approved')->count(),
        );

        $secondAdjustment = AttendanceAdjustment::factory()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'requested_by' => $supervisor->id,
            'status' => 'pending',
        ]);

        $this->actingAs($this->owner)->post(route('attendance.adjustments.reject', $secondAdjustment));

        $this->assertSame(
            1,
            AuditLog::query()->where('entity_id', $secondAdjustment->id)->where('action', 'attendance_adjustment.rejected')->count(),
        );
    }

    public function test_an_adjustment_from_another_company_is_not_visible_or_actionable()
    {
        $otherCompany = Company::factory()->create();
        $foreignEmployee = Employee::factory()->create(['company_id' => $otherCompany->id]);
        $foreignOwner = $this->userWithRole('COMPANY_OWNER', $otherCompany);

        $foreignAdjustment = AttendanceAdjustment::factory()->create([
            'company_id' => $otherCompany->id,
            'employee_id' => $foreignEmployee->id,
            'requested_by' => $foreignOwner->id,
            'status' => 'pending',
        ]);

        // The active company is resolved onto the session by
        // SetCurrentCompany, which runs after route-model-binding
        // middleware (see BranchTest for the same pattern), so a prior
        // request must establish that session state first.
        $client = $this->actingAs($this->owner);
        $client->get(route('employees.attendance.index', $this->employee));

        $client->post(route('attendance.adjustments.approve', $foreignAdjustment))
            ->assertNotFound();

        $client->post(route('employees.attendance.adjustments.store', $foreignEmployee), [
            'type' => 'add',
            'corrected_value' => ['event_type' => 'clock_in', 'event_datetime' => '2026-02-10 08:00:00'],
            'reason' => 'No debería poder crear sobre un empleado ajeno.',
        ])->assertNotFound();
    }

    public function test_an_original_event_from_another_company_cannot_be_referenced()
    {
        $otherCompany = Company::factory()->create();
        $foreignEvent = AttendanceEvent::factory()->create(['company_id' => $otherCompany->id]);

        $this->actingAs($this->owner)->post(route('employees.attendance.adjustments.store', $this->employee), [
            'type' => 'modify',
            'original_event_id' => $foreignEvent->id,
            'corrected_value' => ['event_datetime' => '2026-02-10 08:05:00'],
            'reason' => 'Referencia a evento de otra empresa.',
        ])->assertSessionHasErrors('original_event_id');
    }

    public function test_a_user_without_attendance_adjust_permission_is_denied_on_store()
    {
        $accountant = $this->userWithRole('ACCOUNTANT', $this->company);

        $this->actingAs($accountant)->post(route('employees.attendance.adjustments.store', $this->employee), [
            'type' => 'add',
            'corrected_value' => ['event_type' => 'clock_in', 'event_datetime' => '2026-02-10 08:00:00'],
            'reason' => 'Sin permiso.',
        ])->assertForbidden();
    }

    public function test_a_user_without_approve_permission_is_denied_on_approve_and_reject()
    {
        $supervisor = $this->userWithRole('SUPERVISOR', $this->company);

        $adjustment = AttendanceAdjustment::factory()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'requested_by' => $supervisor->id,
            'status' => 'pending',
        ]);

        $this->actingAs($supervisor)
            ->post(route('attendance.adjustments.approve', $adjustment))
            ->assertForbidden();

        $this->actingAs($supervisor)
            ->post(route('attendance.adjustments.reject', $adjustment))
            ->assertForbidden();
    }
}
