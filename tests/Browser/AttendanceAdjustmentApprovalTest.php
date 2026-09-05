<?php

namespace Tests\Browser;

use App\Models\AttendanceAdjustment;
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
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Carbon;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * Flow B from .ai/21-TESTING.md: a real Chrome browser exercises the manual
 * attendance-correction flow end to end with TWO distinct users, matching
 * what the flow's name promises. A SUPERVISOR (has `attendance.adjust` but
 * NOT `attendance.approve_adjustment`) requests a `modify` adjustment
 * through the real "Solicitar ajuste" form, which stays `pending` because
 * SUPERVISOR cannot auto-approve (ADR-032). A second `loginAs()` swap to an
 * HR_MANAGER (who has both permissions) then clicks the real "Aprobar"
 * button. The result is verified directly in the database: the original
 * AttendanceEvent is untouched (ADR-003), and the AttendanceRecord for the
 * affected date is recalculated to reflect the corrected value.
 */
class AttendanceAdjustmentApprovalTest extends DuskTestCase
{
    use DatabaseTruncation;

    /**
     * Same tolerance/rounding/shift shape as AttendanceToTimeCalculationTest
     * (Flow A) and TimeCalculationEngineTest's documented example, so the
     * expected AttendanceRecord numbers below are already proven correct at
     * the unit level once the corrected clock_in lands exactly on shift
     * start with no anomaly.
     */
    private const DATE = '2026-02-10';

    public function test_a_supervisor_requests_an_adjustment_and_an_hr_manager_approves_it(): void
    {
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $company = Company::factory()->create();
        $employee = Employee::factory()->create(['company_id' => $company->id]);

        $laborRule = LaborRule::factory()->create([
            'company_id' => $company->id,
            'rule_type' => 'STANDARD_WORKWEEK',
        ]);
        LaborRuleVersion::factory()->create([
            'company_id' => $company->id,
            'labor_rule_id' => $laborRule->id,
            'effective_from' => '2026-01-01',
            'effective_to' => null,
            'parameters' => ['tolerance_minutes' => 20, 'rounding_minutes' => 5],
        ]);

        $shift = Shift::factory()->create([
            'company_id' => $company->id,
            'date' => self::DATE,
            'start_datetime' => self::DATE.' 06:00:00',
            'end_datetime' => self::DATE.' 14:00:00',
            'crosses_midnight' => false,
        ]);
        ShiftAssignment::factory()->create([
            'company_id' => $company->id,
            'shift_id' => $shift->id,
            'employee_id' => $employee->id,
            'status' => 'assigned',
        ]);
        ShiftBreak::factory()->create([
            'company_id' => $company->id,
            'shift_id' => $shift->id,
            'planned_start' => self::DATE.' 12:00:00',
            'planned_end' => self::DATE.' 13:00:00',
        ]);

        // The event to be corrected: clock_in was marked 35 minutes late
        // (outside the 20-minute tolerance), which is exactly the kind of
        // mistake the "Solicitar ajuste" flow exists to fix.
        $originalEvent = AttendanceEvent::factory()->create([
            'company_id' => $company->id,
            'employee_id' => $employee->id,
            'event_type' => 'clock_in',
            'event_datetime' => self::DATE.' 06:35:00',
        ]);
        AttendanceEvent::factory()->create([
            'company_id' => $company->id,
            'employee_id' => $employee->id,
            'event_type' => 'break_start',
            'event_datetime' => self::DATE.' 12:00:00',
        ]);
        AttendanceEvent::factory()->create([
            'company_id' => $company->id,
            'employee_id' => $employee->id,
            'event_type' => 'break_end',
            'event_datetime' => self::DATE.' 13:00:00',
        ]);
        AttendanceEvent::factory()->create([
            'company_id' => $company->id,
            'employee_id' => $employee->id,
            'event_type' => 'clock_out',
            'event_datetime' => self::DATE.' 14:00:00',
        ]);

        $supervisorRole = Role::query()->whereNull('company_id')->where('name', 'SUPERVISOR')->firstOrFail();
        $supervisor = User::factory()->create();
        UserCompanyMembership::create([
            'user_id' => $supervisor->id,
            'company_id' => $company->id,
            'role_id' => $supervisorRole->id,
            'status' => 'active',
        ]);

        $hrManagerRole = Role::query()->whereNull('company_id')->where('name', 'HR_MANAGER')->firstOrFail();
        $hrManager = User::factory()->create();
        UserCompanyMembership::create([
            'user_id' => $hrManager->id,
            'company_id' => $company->id,
            'role_id' => $hrManagerRole->id,
            'status' => 'active',
        ]);

        $this->browse(function (Browser $browser) use ($supervisor, $hrManager, $employee, $originalEvent) {
            $browser->loginAs($supervisor)
                ->visit("/employees/{$employee->id}/attendance")
                ->select('#adjustment_type', 'modify')
                ->select('#original_event_id', $originalEvent->id);

            // Browser::script() always returns an array of results (even
            // for a single script string), so it cannot be chained — see
            // Dusk source (already discovered in Flow A's commit).
            $browser->script("document.getElementById('corrected_event_datetime_modify').value = '".self::DATE."T06:00'");

            $browser->type('reason', 'Se marcó la entrada 35 minutos tarde por un error del reloj del dispositivo.')
                ->press('Solicitar ajuste')
                ->waitForText('Pendiente', 10);

            // loginAs() re-authenticates as a different user directly —
            // Dusk does not require an explicit logout() call first.
            $browser->loginAs($hrManager)
                ->visit("/employees/{$employee->id}/attendance")
                ->waitForText('Pendiente', 10)
                ->press('Aprobar')
                ->waitForText('Aprobado', 10);
        });

        $adjustment = AttendanceAdjustment::query()
            ->where('employee_id', $employee->id)
            ->where('original_event_id', $originalEvent->id)
            ->firstOrFail();

        $this->assertSame('approved', $adjustment->status);
        $this->assertSame($hrManager->id, $adjustment->approved_by);
        $this->assertSame($supervisor->id, $adjustment->requested_by);

        // ADR-003: the original event is never edited or deleted, even
        // through a full create-then-approve correction round trip.
        $this->assertDatabaseHas('attendance_events', [
            'id' => $originalEvent->id,
            'event_type' => 'clock_in',
            'event_datetime' => self::DATE.' 06:35:00',
        ]);

        $record = AttendanceRecord::query()
            ->where('employee_id', $employee->id)
            ->where('date', self::DATE)
            ->firstOrFail();

        // The recalculation triggered by approve() reads the corrected
        // 06:00 clock_in via AttendanceNetEventsResolver, never the
        // original (untouched) 06:35 event.
        $this->assertSame(
            Carbon::parse(self::DATE.' 06:00:00')->toIso8601String(),
            $record->worked_json['clock_in'],
        );

        // planned = 480 - 60 = 420, worked = 480 - 60 = 420, diff = 0,
        // within the 20-minute tolerance: no overtime, nothing missing.
        $this->assertSame(420, $record->planned_json['planned_minutes']);
        $this->assertSame(420, $record->worked_json['worked_minutes']);
        $this->assertSame(420, $record->ordinary_minutes);
        $this->assertSame(0, $record->overtime_candidate_minutes);
        $this->assertSame(0, $record->missing_minutes);
    }
}
