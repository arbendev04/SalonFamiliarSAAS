<?php

namespace Tests\Feature;

use App\Models\AttendanceEvent;
use App\Models\AttendanceRecord;
use App\Models\Company;
use App\Models\Employee;
use App\Models\LaborRule;
use App\Models\LaborRuleVersion;
use App\Models\Role;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\ShiftBreak;
use App\Models\User;
use App\Models\UserCompanyMembership;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Support\SessionKey;
use Tests\TestCase;

/**
 * Covers both the attendance_records schema/factory sanity checks (from the
 * schema-only commit) and the AttendanceRecordController read view + manual
 * "recalcular" action (see app/Http/Controllers/AttendanceRecordController.php).
 */
class AttendanceRecordTest extends TestCase
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

    private function configureLaborRule(array $parameters = ['tolerance_minutes' => 15, 'rounding_minutes' => 5]): LaborRuleVersion
    {
        $laborRule = LaborRule::factory()->create([
            'company_id' => $this->company->id,
            'rule_type' => 'STANDARD_WORKWEEK',
        ]);

        return LaborRuleVersion::factory()->create([
            'company_id' => $this->company->id,
            'labor_rule_id' => $laborRule->id,
            'effective_from' => '2026-01-01',
            'effective_to' => null,
            'parameters' => $parameters,
        ]);
    }

    /**
     * Standard shift: 06:00-14:00 on $date with a planned 12:00-13:00 break.
     */
    private function standardShift(string $date): Shift
    {
        $shift = Shift::factory()->create([
            'company_id' => $this->company->id,
            'date' => $date,
            'start_datetime' => "{$date} 06:00:00",
            'end_datetime' => "{$date} 14:00:00",
            'crosses_midnight' => false,
        ]);

        ShiftAssignment::factory()->create([
            'company_id' => $this->company->id,
            'shift_id' => $shift->id,
            'employee_id' => $this->employee->id,
            'status' => 'assigned',
        ]);

        ShiftBreak::factory()->create([
            'company_id' => $this->company->id,
            'shift_id' => $shift->id,
            'planned_start' => "{$date} 12:00:00",
            'planned_end' => "{$date} 13:00:00",
        ]);

        return $shift;
    }

    private function event(string $type, string $datetime): AttendanceEvent
    {
        return AttendanceEvent::factory()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'event_type' => $type,
            'event_datetime' => $datetime,
        ]);
    }

    public function test_a_record_can_be_created_via_factory_with_its_relationships()
    {
        $ruleVersion = LaborRuleVersion::factory()->create(['company_id' => $this->company->id]);

        $record = AttendanceRecord::factory()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'rule_version_id' => $ruleVersion->id,
        ]);

        $this->assertTrue($record->employee->is($this->employee));
        $this->assertTrue($record->ruleVersion->is($ruleVersion));
        $this->assertIsArray($record->planned_json);
        $this->assertIsArray($record->worked_json);
        $this->assertSame($record->date->format('Y-m-d'), $record->date->format('Y-m-d'));
    }

    public function test_a_second_record_for_the_same_employee_and_date_violates_the_unique_constraint()
    {
        AttendanceRecord::factory()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'date' => '2026-02-10',
        ]);

        $this->expectException(QueryException::class);

        AttendanceRecord::factory()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'date' => '2026-02-10',
        ]);
    }

    public function test_index_returns_the_expected_inertia_props_for_a_user_with_read_permission()
    {
        $ruleVersion = $this->configureLaborRule();

        AttendanceRecord::factory()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'rule_version_id' => $ruleVersion->id,
            'date' => '2026-02-10',
            'ordinary_minutes' => 420,
            'overtime_candidate_minutes' => 0,
            'missing_minutes' => 0,
        ]);

        $response = $this->actingAs($this->owner)->get(route('employees.time-calculation.index', $this->employee));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('employees/TimeCalculation')
            ->where('employee.id', $this->employee->id)
            ->where('employee.full_name', $this->employee->full_name)
            ->has('records', 1)
            ->where('records.0.date', '2026-02-10')
            ->where('records.0.ordinary_minutes', 420)
            ->where('canCalculate', true),
        );
    }

    public function test_recalculate_with_valid_dates_creates_the_expected_attendance_record_and_flashes_success()
    {
        $this->configureLaborRule(['tolerance_minutes' => 15, 'rounding_minutes' => 5]);
        $this->standardShift('2026-02-10');
        $this->event('clock_in', '2026-02-10 06:07:00');
        $this->event('break_start', '2026-02-10 12:00:00');
        $this->event('break_end', '2026-02-10 13:00:00');
        $this->event('clock_out', '2026-02-10 14:23:00');

        $response = $this->actingAs($this->owner)->post(route('employees.time-calculation.recalculate', $this->employee), [
            'start_date' => '2026-02-10',
            'end_date' => '2026-02-10',
        ]);

        $response->assertRedirect()->assertSessionHasNoErrors();

        $record = AttendanceRecord::query()
            ->where('employee_id', $this->employee->id)
            ->where('date', '2026-02-10')
            ->first();

        $this->assertNotNull($record);
        $this->assertSame(420, $record->ordinary_minutes);

        $toast = session(SessionKey::FLASH_DATA)['toast'] ?? null;
        $this->assertNotNull($toast);
        $this->assertSame('success', $toast['type']);
        $this->assertStringContainsString('Se calcularon 1 fechas', $toast['message']);
    }

    /**
     * A day with a shift but no clock_out event triggers the engine's
     * MissingCriticalAttendanceEventException, which calculateForRange()
     * catches per-date as status "blocked" rather than aborting the whole
     * range. This mixes one ok date with one blocked date.
     */
    public function test_recalculate_with_a_mix_of_ok_and_blocked_dates_succeeds_overall_and_flash_reflects_the_mixed_outcome()
    {
        $this->configureLaborRule(['tolerance_minutes' => 15, 'rounding_minutes' => 5]);

        $this->standardShift('2026-02-10');
        $this->event('clock_in', '2026-02-10 06:07:00');
        $this->event('break_start', '2026-02-10 12:00:00');
        $this->event('break_end', '2026-02-10 13:00:00');
        $this->event('clock_out', '2026-02-10 14:23:00');

        // Shift assigned but no clock_out -> MissingCriticalAttendanceEventException.
        $this->standardShift('2026-02-11');
        $this->event('clock_in', '2026-02-11 06:07:00');

        $response = $this->actingAs($this->owner)->post(route('employees.time-calculation.recalculate', $this->employee), [
            'start_date' => '2026-02-10',
            'end_date' => '2026-02-11',
        ]);

        $response->assertRedirect()->assertSessionHasNoErrors();
        $response->assertStatus(302);

        $this->assertNotNull(
            AttendanceRecord::query()->where('employee_id', $this->employee->id)->where('date', '2026-02-10')->first(),
        );
        $this->assertNull(
            AttendanceRecord::query()->where('employee_id', $this->employee->id)->where('date', '2026-02-11')->first(),
        );

        $toast = session(SessionKey::FLASH_DATA)['toast'] ?? null;
        $this->assertNotNull($toast);
        $this->assertSame('warning', $toast['type']);
        $this->assertStringContainsString('1 fechas', $toast['message']);
        $this->assertStringContainsString('1 quedaron bloqueadas', $toast['message']);
    }

    public function test_index_is_denied_without_the_time_calculation_read_permission()
    {
        $rankAndFile = $this->userWithRole('EMPLOYEE', $this->company);

        $this->actingAs($rankAndFile)
            ->get(route('employees.time-calculation.index', $this->employee))
            ->assertForbidden();
    }

    public function test_recalculate_is_denied_without_the_time_calculation_calculate_permission()
    {
        // SUPERVISOR has time_calculation.read but not time_calculation.calculate.
        $supervisor = $this->userWithRole('SUPERVISOR', $this->company);

        $this->actingAs($supervisor)
            ->post(route('employees.time-calculation.recalculate', $this->employee), [
                'start_date' => '2026-02-10',
                'end_date' => '2026-02-10',
            ])
            ->assertForbidden();

        $this->assertSame(0, AttendanceRecord::query()->where('employee_id', $this->employee->id)->count());
    }

    public function test_a_user_cannot_view_or_recalculate_attendance_records_for_an_employee_from_another_company()
    {
        $foreignEmployee = Employee::factory()->create(['company_id' => Company::factory()->create()->id]);

        $client = $this->actingAs($this->owner);

        // A warm-up request against the user's own employee establishes
        // the active-company session key (see SetCurrentCompany) that the
        // BelongsToCompany global scope relies on to reject the foreign
        // employee's route-model binding below. Same pattern as
        // AttendanceEventTest::test_an_event_from_another_company_is_not_visible_or_creatable_against_a_foreign_employee.
        $client->get(route('employees.time-calculation.index', $this->employee));

        $client->get(route('employees.time-calculation.index', $foreignEmployee))->assertNotFound();

        $client->post(route('employees.time-calculation.recalculate', $foreignEmployee), [
            'start_date' => '2026-02-10',
            'end_date' => '2026-02-10',
        ])->assertNotFound();
    }
}
