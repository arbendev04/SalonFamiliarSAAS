<?php

namespace Tests\Feature;

use App\Exceptions\AttendanceEventImmutableException;
use App\Models\AttendanceEvent;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use App\Models\UserCompanyMembership;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendanceEventTest extends TestCase
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

    public function test_a_normal_clock_sequence_is_recorded_in_order()
    {
        $client = $this->actingAs($this->owner);

        $client->post(route('employees.attendance.events.store', $this->employee), [
            'event_type' => 'clock_in',
            'event_datetime' => '2026-02-10 08:00:00',
            'source' => 'web',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $client->post(route('employees.attendance.events.store', $this->employee), [
            'event_type' => 'break_start',
            'event_datetime' => '2026-02-10 12:00:00',
            'source' => 'web',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $client->post(route('employees.attendance.events.store', $this->employee), [
            'event_type' => 'break_end',
            'event_datetime' => '2026-02-10 13:00:00',
            'source' => 'web',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $client->post(route('employees.attendance.events.store', $this->employee), [
            'event_type' => 'clock_out',
            'event_datetime' => '2026-02-10 17:00:00',
            'source' => 'web',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $events = AttendanceEvent::query()
            ->where('employee_id', $this->employee->id)
            ->orderBy('event_datetime')
            ->get();

        $this->assertSame(4, $events->count());
        $this->assertSame(['clock_in', 'break_start', 'break_end', 'clock_out'], $events->pluck('event_type')->all());

        foreach ($events as $event) {
            $this->assertNull($event->metadata);
        }
    }

    public function test_recording_a_duplicate_event_within_one_minute_does_not_create_a_second_row()
    {
        $client = $this->actingAs($this->owner);

        $client->post(route('employees.attendance.events.store', $this->employee), [
            'event_type' => 'clock_in',
            'event_datetime' => '2026-02-10 08:00:00',
            'source' => 'web',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $original = AttendanceEvent::query()->where('employee_id', $this->employee->id)->firstOrFail();

        $client->post(route('employees.attendance.events.store', $this->employee), [
            'event_type' => 'clock_in',
            'event_datetime' => '2026-02-10 08:00:45',
            'source' => 'web',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(1, AttendanceEvent::query()->where('employee_id', $this->employee->id)->count());
        $this->assertSame($original->id, AttendanceEvent::query()->where('employee_id', $this->employee->id)->firstOrFail()->id);
    }

    public function test_an_out_of_order_event_is_accepted_and_marked_as_an_anomaly()
    {
        $client = $this->actingAs($this->owner);

        $client->post(route('employees.attendance.events.store', $this->employee), [
            'event_type' => 'clock_in',
            'event_datetime' => '2026-02-10 08:00:00',
            'source' => 'web',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $response = $client->post(route('employees.attendance.events.store', $this->employee), [
            'event_type' => 'break_end',
            'event_datetime' => '2026-02-10 09:00:00',
            'source' => 'web',
        ]);

        $response->assertRedirect()->assertSessionHasNoErrors();

        $breakEnd = AttendanceEvent::query()
            ->where('employee_id', $this->employee->id)
            ->where('event_type', 'break_end')
            ->firstOrFail();

        $this->assertSame('out_of_sequence', $breakEnd->metadata['anomaly'] ?? null);
        $this->assertSame(2, AttendanceEvent::query()->where('employee_id', $this->employee->id)->count());
    }

    public function test_updating_an_attendance_event_instance_throws()
    {
        $event = AttendanceEvent::factory()->create(['company_id' => $this->company->id, 'employee_id' => $this->employee->id]);

        $this->expectException(AttendanceEventImmutableException::class);

        $event->update(['event_type' => 'clock_out']);
    }

    public function test_deleting_an_attendance_event_instance_throws()
    {
        $event = AttendanceEvent::factory()->create(['company_id' => $this->company->id, 'employee_id' => $this->employee->id]);

        $this->expectException(AttendanceEventImmutableException::class);

        $event->delete();
    }

    public function test_updating_via_query_builder_throws()
    {
        AttendanceEvent::factory()->create(['company_id' => $this->company->id, 'employee_id' => $this->employee->id]);

        $this->expectException(AttendanceEventImmutableException::class);

        AttendanceEvent::query()->where('employee_id', $this->employee->id)->update(['event_type' => 'clock_out']);
    }

    public function test_deleting_via_query_builder_throws()
    {
        AttendanceEvent::factory()->create(['company_id' => $this->company->id, 'employee_id' => $this->employee->id]);

        $this->expectException(AttendanceEventImmutableException::class);

        AttendanceEvent::query()->where('employee_id', $this->employee->id)->delete();
    }

    public function test_an_event_from_another_company_is_not_visible_or_creatable_against_a_foreign_employee()
    {
        $otherCompany = Company::factory()->create();
        $foreignEmployee = Employee::factory()->create(['company_id' => $otherCompany->id]);

        $client = $this->actingAs($this->owner);
        $client->get(route('employees.attendance.index', $this->employee));

        $client->post(route('employees.attendance.events.store', $foreignEmployee), [
            'event_type' => 'clock_in',
            'event_datetime' => '2026-02-10 08:00:00',
            'source' => 'web',
        ])->assertNotFound();
    }

    public function test_a_user_without_attendance_record_permission_is_denied_on_store()
    {
        $accountantRole = Role::query()->whereNull('company_id')->where('name', 'ACCOUNTANT')->firstOrFail();

        $rankAndFile = User::factory()->create();

        UserCompanyMembership::create([
            'user_id' => $rankAndFile->id,
            'company_id' => $this->company->id,
            'role_id' => $accountantRole->id,
            'status' => 'active',
        ]);

        $this->actingAs($rankAndFile)->post(route('employees.attendance.events.store', $this->employee), [
            'event_type' => 'clock_in',
            'event_datetime' => '2026-02-10 08:00:00',
            'source' => 'web',
        ])->assertForbidden();
    }

    public function test_a_user_without_attendance_read_permission_is_denied_on_index()
    {
        $employeeRole = Role::query()->whereNull('company_id')->where('name', 'EMPLOYEE')->firstOrFail();

        $rankAndFile = User::factory()->create();

        UserCompanyMembership::create([
            'user_id' => $rankAndFile->id,
            'company_id' => $this->company->id,
            'role_id' => $employeeRole->id,
            'status' => 'active',
        ]);

        $this->actingAs($rankAndFile)
            ->get(route('employees.attendance.index', $this->employee))
            ->assertForbidden();
    }

    public function test_an_invalid_source_is_rejected()
    {
        $this->actingAs($this->owner)->post(route('employees.attendance.events.store', $this->employee), [
            'event_type' => 'clock_in',
            'event_datetime' => '2026-02-10 08:00:00',
            'source' => 'biometric',
        ])->assertSessionHasErrors('source');

        $this->assertSame(0, AttendanceEvent::query()->count());
    }
}
