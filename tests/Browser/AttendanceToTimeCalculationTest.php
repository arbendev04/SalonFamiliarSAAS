<?php

namespace Tests\Browser;

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
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * Flow A from .ai/21-TESTING.md (stand-in for the biometric-clock roadmap
 * flow, which is post-MVP per .ai/25-MVP-SCOPE.md): a real Chrome browser
 * fichas an employee in/out through the actual Attendance UI, then triggers
 * a real recalculation through the actual TimeCalculation UI, and the
 * resulting numbers are verified directly in the database — not just read
 * back off the rendered page.
 */
class AttendanceToTimeCalculationTest extends DuskTestCase
{
    use DatabaseTruncation;

    /**
     * Same tolerance/rounding/shift fixture as
     * TimeCalculationEngineTest::test_the_docs_numeric_example_with_tolerance_20_has_no_overtime_and_no_missing(),
     * so the expected numbers below are already proven correct at the unit
     * level — this test only proves the real UI produces that same input.
     */
    private const DATE = '2026-02-10';

    public function test_recording_an_attendance_event_and_recalculating_produces_a_correct_attendance_record(): void
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

        $role = Role::query()->whereNull('company_id')->where('name', 'HR_MANAGER')->firstOrFail();
        $user = User::factory()->create();
        UserCompanyMembership::create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'role_id' => $role->id,
            'status' => 'active',
        ]);

        $this->browse(function (Browser $browser) use ($user, $employee) {
            $browser->loginAs($user)
                ->visit("/employees/{$employee->id}/attendance");

            $this->ficha($browser, 'clock_in', self::DATE.'T06:07', '2026-02-10 06:07:00');
            $this->ficha($browser, 'break_start', self::DATE.'T12:00', '2026-02-10 12:00:00');
            $this->ficha($browser, 'break_end', self::DATE.'T13:00', '2026-02-10 13:00:00');
            $this->ficha($browser, 'clock_out', self::DATE.'T14:23', '2026-02-10 14:23:00');

            $browser->visit("/employees/{$employee->id}/time-calculation")
                ->script([
                    "document.getElementById('start_date').value = '".self::DATE."'",
                    "document.getElementById('end_date').value = '".self::DATE."'",
                ]);

            $browser->press('Recalcular')
                ->waitForText('420', 10);
        });

        $this->assertSame(4, AttendanceEvent::query()->where('employee_id', $employee->id)->count());

        $expectedEvents = [
            ['clock_in', '2026-02-10 06:07:00'],
            ['break_start', '2026-02-10 12:00:00'],
            ['break_end', '2026-02-10 13:00:00'],
            ['clock_out', '2026-02-10 14:23:00'],
        ];

        foreach ($expectedEvents as [$type, $datetime]) {
            $this->assertDatabaseHas('attendance_events', [
                'employee_id' => $employee->id,
                'event_type' => $type,
                'event_datetime' => $datetime,
            ]);
        }

        $record = AttendanceRecord::query()
            ->where('employee_id', $employee->id)
            ->where('date', self::DATE)
            ->firstOrFail();

        // planned = 480 - 60 = 420, worked = 496 - 60 = 436, diff = +16,
        // within the 20-minute tolerance: no overtime, nothing missing.
        $this->assertSame(420, $record->planned_json['planned_minutes']);
        $this->assertSame(436, $record->worked_json['worked_minutes']);
        $this->assertSame(420, $record->ordinary_minutes);
        $this->assertSame(0, $record->overtime_candidate_minutes);
        $this->assertSame(0, $record->missing_minutes);
    }

    private function ficha(Browser $browser, string $eventType, string $datetimeLocalValue, string $expectedRowText): void
    {
        $browser->select('#event_type', $eventType);
        // Browser::script() always returns an array of results (even for a
        // single script string), so it cannot be chained — see Dusk source.
        $browser->script("document.getElementById('event_datetime').value = '{$datetimeLocalValue}'");
        $browser->select('#source', 'web')
            ->press('Fichar')
            ->waitForText($expectedRowText, 10);
    }
}
