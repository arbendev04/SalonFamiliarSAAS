<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeSchedule;
use App\Models\Role;
use App\Models\User;
use App\Models\UserCompanyMembership;
use App\Models\WorkScheduleTemplate;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ScheduleManagementTest extends TestCase
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

    public function test_creating_a_template_also_creates_its_day_rules()
    {
        $this->actingAs($this->owner)->post(route('schedules.store'), [
            'name' => 'Turno panadería',
            'days' => [
                ['day_of_week' => 1, 'start_time' => '06:00', 'end_time' => '14:00'],
                ['day_of_week' => 2, 'start_time' => '06:00', 'end_time' => '14:00'],
            ],
        ])->assertRedirect();

        $template = WorkScheduleTemplate::query()->where('name', 'Turno panadería')->firstOrFail();

        $this->assertSame($this->company->id, $template->company_id);
        $this->assertSame(2, $template->days()->count());
    }

    public function test_a_break_window_outside_the_days_start_and_end_time_is_rejected()
    {
        $this->actingAs($this->owner)->post(route('schedules.store'), [
            'name' => 'Turno panadería',
            'days' => [
                [
                    'day_of_week' => 1,
                    'start_time' => '06:00',
                    'end_time' => '14:00',
                    'break_start_time' => '15:00',
                    'break_end_time' => '15:30',
                ],
            ],
        ])->assertSessionHasErrors('days.0.break_start_time');

        $this->assertSame(0, WorkScheduleTemplate::query()->count());
    }

    public function test_only_one_of_break_start_time_or_break_end_time_present_is_rejected()
    {
        $this->actingAs($this->owner)->post(route('schedules.store'), [
            'name' => 'Turno panadería',
            'days' => [
                [
                    'day_of_week' => 1,
                    'start_time' => '06:00',
                    'end_time' => '14:00',
                    'break_start_time' => '10:00',
                ],
            ],
        ])->assertSessionHasErrors('days.0.break_start_time');

        $this->assertSame(0, WorkScheduleTemplate::query()->count());
    }

    public function test_a_break_window_valid_across_a_crosses_midnight_day_is_accepted()
    {
        $this->actingAs($this->owner)->post(route('schedules.store'), [
            'name' => 'Turno nocturno',
            'days' => [
                [
                    'day_of_week' => 2,
                    'start_time' => '22:00',
                    'end_time' => '06:00',
                    'crosses_midnight' => true,
                    // 00:30 is earlier than start_time as a clock time, so
                    // it correctly anchors to the day after start — inside
                    // the wrapped 22:00->06:00 window.
                    'break_start_time' => '00:30',
                    'break_end_time' => '01:00',
                ],
            ],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $template = WorkScheduleTemplate::query()->where('name', 'Turno nocturno')->firstOrFail();
        $day = $template->days()->firstOrFail();

        $this->assertSame('00:30', Carbon::parse($day->break_start_time)->format('H:i'));
        $this->assertSame('01:00', Carbon::parse($day->break_end_time)->format('H:i'));
    }

    public function test_assigning_a_new_template_closes_the_previous_assignment()
    {
        $firstTemplate = WorkScheduleTemplate::factory()->create(['company_id' => $this->company->id]);
        $secondTemplate = WorkScheduleTemplate::factory()->create(['company_id' => $this->company->id]);

        $this->actingAs($this->owner)->post(route('employees.schedule.store', $this->employee), [
            'template_id' => $firstTemplate->id,
            'effective_from' => '2026-01-01',
        ])->assertRedirect();

        $this->actingAs($this->owner)->post(route('employees.schedule.store', $this->employee), [
            'template_id' => $secondTemplate->id,
            'effective_from' => '2026-03-01',
        ])->assertRedirect();

        $first = EmployeeSchedule::query()->where('template_id', $firstTemplate->id)->firstOrFail();
        $second = EmployeeSchedule::query()->where('template_id', $secondTemplate->id)->firstOrFail();

        $this->assertSame('2026-02-28', $first->effective_to->toDateString());
        $this->assertNull($second->effective_to);
    }

    public function test_assigning_a_schedule_with_no_previous_assignment_logs_an_audit_entry_with_no_old_value()
    {
        $template = WorkScheduleTemplate::factory()->create(['company_id' => $this->company->id]);

        $this->actingAs($this->owner)->post(route('employees.schedule.store', $this->employee), [
            'template_id' => $template->id,
            'effective_from' => '2026-01-01',
        ])->assertRedirect();

        $schedule = EmployeeSchedule::query()->where('template_id', $template->id)->firstOrFail();

        $auditLog = AuditLog::query()
            ->where('entity_type', 'employee_schedules')
            ->where('entity_id', $schedule->id)
            ->where('action', 'employee_schedule.assigned')
            ->firstOrFail();

        $this->assertNull($auditLog->old_value);
        $this->assertSame($template->id, $auditLog->new_value['template_id']);
        $this->assertSame($this->employee->id, $auditLog->new_value['employee_id']);
    }

    public function test_reassigning_a_schedule_logs_an_audit_entry_with_the_previous_assignment_as_old_value()
    {
        $firstTemplate = WorkScheduleTemplate::factory()->create(['company_id' => $this->company->id]);
        $secondTemplate = WorkScheduleTemplate::factory()->create(['company_id' => $this->company->id]);

        $this->actingAs($this->owner)->post(route('employees.schedule.store', $this->employee), [
            'template_id' => $firstTemplate->id,
            'effective_from' => '2026-01-01',
        ])->assertRedirect();

        $this->actingAs($this->owner)->post(route('employees.schedule.store', $this->employee), [
            'template_id' => $secondTemplate->id,
            'effective_from' => '2026-03-01',
        ])->assertRedirect();

        $second = EmployeeSchedule::query()->where('template_id', $secondTemplate->id)->firstOrFail();

        $auditLog = AuditLog::query()
            ->where('entity_type', 'employee_schedules')
            ->where('entity_id', $second->id)
            ->where('action', 'employee_schedule.assigned')
            ->firstOrFail();

        $this->assertSame($firstTemplate->id, $auditLog->old_value['template_id']);
        $this->assertNull($auditLog->old_value['effective_to']);
        $this->assertSame($secondTemplate->id, $auditLog->new_value['template_id']);
        $this->assertSame('2026-03-01', $auditLog->new_value['effective_from']);
    }

    public function test_a_template_belonging_to_another_company_cannot_be_assigned()
    {
        $foreignTemplate = WorkScheduleTemplate::factory()->create(['company_id' => Company::factory()->create()->id]);

        $this->actingAs($this->owner)->post(route('employees.schedule.store', $this->employee), [
            'template_id' => $foreignTemplate->id,
            'effective_from' => '2026-01-01',
        ])->assertSessionHasErrors('template_id');
    }

    public function test_a_user_without_the_schedules_write_permission_is_denied()
    {
        $employeeRole = Role::query()->whereNull('company_id')->where('name', 'EMPLOYEE')->firstOrFail();
        $rankAndFile = User::factory()->create();

        UserCompanyMembership::create([
            'user_id' => $rankAndFile->id,
            'company_id' => $this->company->id,
            'role_id' => $employeeRole->id,
            'status' => 'active',
        ]);

        $this->actingAs($rankAndFile)->post(route('schedules.store'), [
            'name' => 'Intento no autorizado',
            'days' => [['day_of_week' => 1, 'start_time' => '06:00', 'end_time' => '14:00']],
        ])->assertForbidden();
    }
}
