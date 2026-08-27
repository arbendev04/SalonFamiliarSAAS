<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Role;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\User;
use App\Models\UserCompanyMembership;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShiftAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private Employee $originalEmployee;

    private Employee $replacementEmployee;

    private Shift $shift;

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

        $this->originalEmployee = Employee::factory()->create(['company_id' => $this->company->id]);
        $this->replacementEmployee = Employee::factory()->create(['company_id' => $this->company->id]);

        $this->shift = Shift::factory()->create([
            'company_id' => $this->company->id,
            'date' => '2026-02-10',
            'start_datetime' => '2026-02-10 06:00:00',
            'end_datetime' => '2026-02-10 14:00:00',
        ]);

        ShiftAssignment::factory()->create([
            'company_id' => $this->company->id,
            'shift_id' => $this->shift->id,
            'employee_id' => $this->originalEmployee->id,
            'status' => 'assigned',
        ]);
    }

    public function test_reassigning_a_shift_cancels_the_previous_assignment_and_records_an_audit_log_entry()
    {
        $this->actingAs($this->owner)->post(route('shifts.assignment.update', $this->shift), [
            'employee_id' => $this->replacementEmployee->id,
            'reason' => 'El empleado original se enfermó.',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(
            'cancelled',
            ShiftAssignment::query()->where('employee_id', $this->originalEmployee->id)->firstOrFail()->status,
        );

        $newAssignment = ShiftAssignment::query()->where('employee_id', $this->replacementEmployee->id)->firstOrFail();
        $this->assertSame('assigned', $newAssignment->status);

        $log = AuditLog::query()->where('entity_id', $this->shift->id)->firstOrFail();
        $this->assertSame('shift_assignment.reassigned', $log->action);
        $this->assertSame($this->owner->id, $log->user_id);
        $this->assertSame($this->originalEmployee->id, $log->old_value['employee_id']);
        $this->assertSame($this->replacementEmployee->id, $log->new_value['employee_id']);
        $this->assertSame('El empleado original se enfermó.', $log->reason);
    }

    public function test_a_reason_is_required_to_reassign_a_shift()
    {
        $this->actingAs($this->owner)->post(route('shifts.assignment.update', $this->shift), [
            'employee_id' => $this->replacementEmployee->id,
        ])->assertSessionHasErrors('reason');
    }

    public function test_reassigning_to_an_employee_who_already_has_an_overlapping_shift_is_rejected()
    {
        $conflictingShift = Shift::factory()->create([
            'company_id' => $this->company->id,
            'date' => '2026-02-10',
            'start_datetime' => '2026-02-10 10:00:00',
            'end_datetime' => '2026-02-10 18:00:00',
        ]);

        ShiftAssignment::factory()->create([
            'company_id' => $this->company->id,
            'shift_id' => $conflictingShift->id,
            'employee_id' => $this->replacementEmployee->id,
            'status' => 'assigned',
        ]);

        $this->actingAs($this->owner)->post(route('shifts.assignment.update', $this->shift), [
            'employee_id' => $this->replacementEmployee->id,
            'reason' => 'Cobertura',
        ])->assertSessionHasErrors('employee_id');
    }
}
