<?php

namespace Tests\Feature;

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
