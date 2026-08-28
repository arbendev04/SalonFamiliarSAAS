<?php

namespace Tests\Feature;

use App\Models\AttendanceAdjustment;
use App\Models\AttendanceEvent;
use App\Models\AttendanceRecord;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Employee;
use App\Models\LaborRule;
use App\Models\LaborRuleVersion;
use App\Models\Role;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\ShiftBreak;
use App\Models\TimeCalculationRun;
use App\Models\User;
use App\Models\UserCompanyMembership;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Flujo 2, step 6 of .ai/07-ATTENDANCE.md: an approved attendance
 * adjustment must trigger TimeCalculationEngine::calculateForDate() for the
 * date it affects. See .ai/09-TIME-CALCULATION.md for the engine itself.
 */
class TimeCalculationRecalculationTest extends TestCase
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

    public function test_approving_a_modify_adjustment_triggers_recalculation_with_the_corrected_value()
    {
        $this->configureLaborRule(['tolerance_minutes' => 100, 'rounding_minutes' => 1]);
        $this->standardShift('2026-02-10');
        $this->event('clock_in', '2026-02-10 06:00:00');
        $this->event('break_start', '2026-02-10 12:00:00');
        $this->event('break_end', '2026-02-10 13:00:00');
        $rawClockOut = $this->event('clock_out', '2026-02-10 14:00:00');

        $supervisor = $this->userWithRole('SUPERVISOR', $this->company);

        $this->actingAs($supervisor)->post(route('employees.attendance.adjustments.store', $this->employee), [
            'type' => 'modify',
            'original_event_id' => $rawClockOut->id,
            'corrected_value' => ['event_datetime' => '2026-02-10 15:00:00'],
            'reason' => 'Marcó salida más tarde de lo registrado.',
        ]);

        $adjustment = AttendanceAdjustment::query()->where('employee_id', $this->employee->id)->firstOrFail();

        $this->actingAs($this->owner)
            ->post(route('attendance.adjustments.approve', $adjustment))
            ->assertRedirect()->assertSessionHasNoErrors();

        $record = AttendanceRecord::query()
            ->where('employee_id', $this->employee->id)
            ->where('date', '2026-02-10')
            ->first();

        $this->assertNotNull($record);
        // gross worked becomes 06:00->15:00 = 540, minus 60 break = 480 —
        // reflects the CORRECTED clock_out, not the original 14:00 one.
        $this->assertSame(480, $record->worked_json['worked_minutes']);
    }

    public function test_the_original_event_is_untouched_after_the_recalculation_it_triggers()
    {
        $this->configureLaborRule(['tolerance_minutes' => 100, 'rounding_minutes' => 1]);
        $this->standardShift('2026-02-10');
        $this->event('clock_in', '2026-02-10 06:00:00');
        $rawClockOut = $this->event('clock_out', '2026-02-10 14:00:00');

        $this->actingAs($this->owner)->post(route('employees.attendance.adjustments.store', $this->employee), [
            'type' => 'modify',
            'original_event_id' => $rawClockOut->id,
            'corrected_value' => ['event_datetime' => '2026-02-10 15:00:00'],
            'reason' => 'Corrección auto-aprobada por el owner.',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('attendance_events', [
            'id' => $rawClockOut->id,
            'event_type' => 'clock_out',
            'event_datetime' => '2026-02-10 14:00:00',
        ]);
    }

    public function test_a_fresh_time_calculation_run_row_is_appended_for_the_triggered_recalculation()
    {
        $ruleVersion = $this->configureLaborRule(['tolerance_minutes' => 100, 'rounding_minutes' => 1]);
        $this->standardShift('2026-02-10');
        $this->event('clock_in', '2026-02-10 06:00:00');
        $rawClockOut = $this->event('clock_out', '2026-02-10 14:00:00');

        $this->assertSame(0, TimeCalculationRun::query()->count());

        $this->actingAs($this->owner)->post(route('employees.attendance.adjustments.store', $this->employee), [
            'type' => 'modify',
            'original_event_id' => $rawClockOut->id,
            'corrected_value' => ['event_datetime' => '2026-02-10 15:00:00'],
            'reason' => 'Corrección auto-aprobada por el owner.',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(1, TimeCalculationRun::query()->count());
        $this->assertSame(
            1,
            TimeCalculationRun::query()
                ->where('employee_id', $this->employee->id)
                ->where('date', '2026-02-10')
                ->where('rule_version_id', $ruleVersion->id)
                ->count(),
        );
    }

    public function test_approving_an_adjustment_succeeds_even_without_a_configured_labor_rule_version()
    {
        // No LaborRule/LaborRuleVersion configured at all for this company.
        $this->standardShift('2026-02-10');
        $rawClockOut = $this->event('clock_out', '2026-02-10 14:00:00');

        $supervisor = $this->userWithRole('SUPERVISOR', $this->company);

        $this->actingAs($supervisor)->post(route('employees.attendance.adjustments.store', $this->employee), [
            'type' => 'modify',
            'original_event_id' => $rawClockOut->id,
            'corrected_value' => ['event_datetime' => '2026-02-10 15:00:00'],
            'reason' => 'Corrección solicitada, sin permiso de auto-aprobación.',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $adjustment = AttendanceAdjustment::query()->where('employee_id', $this->employee->id)->firstOrFail();
        $this->assertSame('pending', $adjustment->status);

        $this->actingAs($this->owner)
            ->post(route('attendance.adjustments.approve', $adjustment))
            ->assertRedirect()->assertSessionHasNoErrors();

        $adjustment->refresh();
        $this->assertSame('approved', $adjustment->status);
        $this->assertSame(
            1,
            AuditLog::query()
                ->where('entity_id', $adjustment->id)
                ->where('action', 'attendance_adjustment.approved')
                ->count(),
        );

        $this->assertSame(0, AttendanceRecord::query()->where('employee_id', $this->employee->id)->count());
    }

    public function test_auto_approved_creation_also_triggers_recalculation()
    {
        $this->configureLaborRule(['tolerance_minutes' => 15, 'rounding_minutes' => 5]);
        $this->standardShift('2026-02-10');
        $this->event('clock_in', '2026-02-10 06:07:00');

        $this->actingAs($this->owner)->post(route('employees.attendance.adjustments.store', $this->employee), [
            'type' => 'add',
            'corrected_value' => ['event_type' => 'clock_out', 'event_datetime' => '2026-02-10 14:23:00'],
            'reason' => 'Olvidó marcar la salida.',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $adjustment = AttendanceAdjustment::query()->where('employee_id', $this->employee->id)->firstOrFail();
        $this->assertSame('approved', $adjustment->status);

        $record = AttendanceRecord::query()
            ->where('employee_id', $this->employee->id)
            ->where('date', '2026-02-10')
            ->first();

        $this->assertNotNull($record);
        $this->assertSame(1, TimeCalculationRun::query()->count());
    }
}
