<?php

namespace Tests\Unit;

use App\Exceptions\AmbiguousLaborRuleVersionException;
use App\Exceptions\InvalidOvertimeRecordStatusException;
use App\Exceptions\MissingCriticalAttendanceEventException;
use App\Exceptions\MissingLaborRuleParameterException;
use App\Exceptions\NoActiveLaborRuleVersionException;
use App\Models\AttendanceAdjustment;
use App\Models\AttendanceEvent;
use App\Models\AttendanceRecord;
use App\Models\Company;
use App\Models\Employee;
use App\Models\LaborRule;
use App\Models\LaborRuleVersion;
use App\Models\LeaveRecord;
use App\Models\LeaveType;
use App\Models\NoveltyRecord;
use App\Models\NoveltyType;
use App\Models\OvertimeRecord;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\ShiftBreak;
use App\Models\TimeCalculationRun;
use App\Models\User;
use App\Services\Overtime\OvertimeRecordService;
use App\Services\Tenancy\CurrentCompany;
use App\Services\TimeCalculation\AttendanceNetEventsResolver;
use App\Services\TimeCalculation\NoveltyRecordLookup;
use App\Services\TimeCalculation\TimeCalculationEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class TimeCalculationEngineTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Employee $employee;

    private TimeCalculationEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->employee = Employee::factory()->create(['company_id' => $this->company->id]);
        $this->engine = new TimeCalculationEngine(new AttendanceNetEventsResolver, new NoveltyRecordLookup);
    }

    /**
     * Builds an APPROVED novelty covering [$dateFrom, $dateTo], created the
     * way App\Services\Leave\LeaveRecordService::generateNoveltyAndAbsence()
     * actually creates one (an approved LeaveRecord behind it, source_type/
     * source_id pointing back to it, status mirroring the leave record's own
     * status) rather than a shortcut that skips fields the real path would
     * populate. The service itself is not invoked because this is engine
     * unit-test scope: NoveltyRecordLookup (already covered by its own test)
     * only ever reads the novelty_records row, never the leave_records
     * lifecycle that produced it.
     */
    private function approvedNoveltyCovering(string $dateFrom, ?string $dateTo = null): NoveltyRecord
    {
        $dateTo ??= $dateFrom;

        $leaveType = LeaveType::factory()->create(['company_id' => $this->company->id]);
        $noveltyType = NoveltyType::factory()->create([
            'company_id' => $this->company->id,
            'code' => $leaveType->code,
            'affects_time_calc' => true,
        ]);

        $leaveRecord = LeaveRecord::factory()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'leave_type_id' => $leaveType->id,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'status' => 'approved',
        ]);

        return NoveltyRecord::factory()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'novelty_type_id' => $noveltyType->id,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'source_type' => 'leave_records',
            'source_id' => $leaveRecord->id,
            'status' => 'approved',
        ]);
    }

    private function ruleVersion(array $parameters, ?string $effectiveFrom = null, ?string $effectiveTo = null): LaborRuleVersion
    {
        $laborRule = LaborRule::query()
            ->where('company_id', $this->company->id)
            ->where('rule_type', 'STANDARD_WORKWEEK')
            ->first() ?? LaborRule::factory()->create([
                'company_id' => $this->company->id,
                'rule_type' => 'STANDARD_WORKWEEK',
            ]);

        return LaborRuleVersion::factory()->create([
            'company_id' => $this->company->id,
            'labor_rule_id' => $laborRule->id,
            'effective_from' => $effectiveFrom ?? '2026-01-01',
            'effective_to' => $effectiveTo,
            'parameters' => $parameters,
        ]);
    }

    /**
     * Standard shift: 06:00-14:00 on $date with a planned 12:00-13:00 break.
     */
    private function standardShift(string $date, bool $crossesMidnight = false, ?string $endDate = null): Shift
    {
        $endDate ??= $date;

        $shift = Shift::factory()->create([
            'company_id' => $this->company->id,
            'date' => $date,
            'start_datetime' => "{$date} 06:00:00",
            'end_datetime' => "{$endDate} 14:00:00",
            'crosses_midnight' => $crossesMidnight,
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

    public function test_the_docs_numeric_example_with_tolerance_20_has_no_overtime_and_no_missing()
    {
        $this->ruleVersion(['tolerance_minutes' => 20, 'rounding_minutes' => 5]);
        $this->standardShift('2026-02-10');
        $this->event('clock_in', '2026-02-10 06:07:00');
        $this->event('break_start', '2026-02-10 12:00:00');
        $this->event('break_end', '2026-02-10 13:00:00');
        $this->event('clock_out', '2026-02-10 14:23:00');

        $record = $this->engine->calculateForDate($this->employee, Carbon::parse('2026-02-10'));

        // planned = 480 - 60 = 420, worked = 496 - 60 = 436, diff = +16.
        $this->assertSame(420, $record->planned_json['planned_minutes']);
        $this->assertSame(436, $record->worked_json['worked_minutes']);
        $this->assertSame(420, $record->ordinary_minutes);
        $this->assertSame(0, $record->overtime_candidate_minutes);
        $this->assertSame(0, $record->missing_minutes);
    }

    public function test_the_docs_numeric_example_with_tolerance_15_detects_a_one_minute_overtime_candidate()
    {
        $this->ruleVersion(['tolerance_minutes' => 15, 'rounding_minutes' => 1]);
        $this->standardShift('2026-02-10');
        $this->event('clock_in', '2026-02-10 06:07:00');
        $this->event('break_start', '2026-02-10 12:00:00');
        $this->event('break_end', '2026-02-10 13:00:00');
        $this->event('clock_out', '2026-02-10 14:23:00');

        $record = $this->engine->calculateForDate($this->employee, Carbon::parse('2026-02-10'));

        // diff +16, excess over tolerance 15 is 1 minute (rounding_minutes=1
        // keeps the pre-rounding value visible for the assertion).
        $this->assertSame(1, $record->overtime_candidate_minutes);
        $this->assertSame(0, $record->missing_minutes);
    }

    public function test_rounding_is_applied_to_the_classified_minutes()
    {
        // tolerance 15, rounding 5: diff +16 -> excess 1 -> rounds to 0.
        $this->ruleVersion(['tolerance_minutes' => 15, 'rounding_minutes' => 5]);
        $this->standardShift('2026-02-10');
        $this->event('clock_in', '2026-02-10 06:07:00');
        $this->event('break_start', '2026-02-10 12:00:00');
        $this->event('break_end', '2026-02-10 13:00:00');
        $this->event('clock_out', '2026-02-10 14:23:00');

        $record = $this->engine->calculateForDate($this->employee, Carbon::parse('2026-02-10'));

        $this->assertSame(0, $record->overtime_candidate_minutes);

        // Add a later clock_out; the engine takes the LAST clock_out
        // chronologically, so this becomes the effective one.
        $this->event('clock_out', '2026-02-10 14:34:00');
        $record = $this->engine->calculateForDate($this->employee, Carbon::parse('2026-02-10'));

        // gross worked = 08:27, minus 60 break = 447, planned 420, diff +27,
        // excess over tolerance 15 = 12 -> rounds to 10.
        $this->assertSame(10, $record->overtime_candidate_minutes);
    }

    public function test_a_shift_crossing_midnight_produces_a_single_record_dated_to_the_shift_date()
    {
        $this->ruleVersion(['tolerance_minutes' => 10, 'rounding_minutes' => 5]);

        $shift = Shift::factory()->create([
            'company_id' => $this->company->id,
            'date' => '2026-02-10',
            'start_datetime' => '2026-02-10 22:00:00',
            'end_datetime' => '2026-02-11 06:00:00',
            'crosses_midnight' => true,
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
            'planned_start' => '2026-02-11 01:00:00',
            'planned_end' => '2026-02-11 01:30:00',
        ]);

        $this->event('clock_in', '2026-02-10 21:55:00');
        $this->event('break_start', '2026-02-11 01:00:00');
        $this->event('break_end', '2026-02-11 01:30:00');
        $this->event('clock_out', '2026-02-11 06:05:00');

        $record = $this->engine->calculateForDate($this->employee, Carbon::parse('2026-02-10'));

        $this->assertSame('2026-02-10', $record->date->format('Y-m-d'));
        $this->assertSame(1, AttendanceRecord::query()->count());

        // planned = 480 - 30 = 450, worked = (21:55->06:05 = 490) - 30 = 460, diff +10.
        $this->assertSame(450, $record->planned_json['planned_minutes']);
        $this->assertSame(460, $record->worked_json['worked_minutes']);
    }

    public function test_no_shift_assigned_returns_null_and_writes_nothing()
    {
        $this->ruleVersion(['tolerance_minutes' => 10, 'rounding_minutes' => 5]);

        $record = $this->engine->calculateForDate($this->employee, Carbon::parse('2026-02-10'));

        $this->assertNull($record);
        $this->assertSame(0, AttendanceRecord::query()->count());
        $this->assertSame(0, TimeCalculationRun::query()->count());
    }

    public function test_zero_attendance_events_is_a_full_absence_with_missing_minutes_equal_to_planned()
    {
        $this->ruleVersion(['tolerance_minutes' => 15, 'rounding_minutes' => 5]);
        $this->standardShift('2026-02-10');

        $record = $this->engine->calculateForDate($this->employee, Carbon::parse('2026-02-10'));

        // planned = 420, already a multiple of 5, so rounding doesn't change it.
        $this->assertSame(420, $record->missing_minutes);
        $this->assertSame(0, $record->ordinary_minutes);
        $this->assertSame(0, $record->overtime_candidate_minutes);
        $this->assertSame(0, $record->worked_json['worked_minutes']);

        // Regression guard for Fase 8: a full absence with NO covering
        // novelty stays entirely unjustified.
        $this->assertSame(0, $record->justified_minutes);
        $this->assertNull($record->justification_json);
    }

    /**
     * Roadmap-mandated acceptance test for Fase 8: an approved leave record
     * (leave_records -> novelty_records, via approvedNoveltyCovering()) makes
     * a full-day absence justified instead of missing.
     */
    public function test_an_approved_leave_record_makes_a_full_absence_justified_not_missing()
    {
        $this->ruleVersion(['tolerance_minutes' => 15, 'rounding_minutes' => 5]);
        $this->standardShift('2026-02-10');
        $novelty = $this->approvedNoveltyCovering('2026-02-10');

        $record = $this->engine->calculateForDate($this->employee, Carbon::parse('2026-02-10'));

        // planned = 420, already a multiple of 5.
        $this->assertSame(0, $record->missing_minutes);
        $this->assertSame(420, $record->justified_minutes);
        $this->assertSame(
            [
                'novelty_record_id' => $novelty->id,
                'novelty_type_code' => $novelty->noveltyType->code,
            ],
            $record->justification_json,
        );
    }

    /**
     * Edge case explicitly out of this phase's scope (see classify()'s
     * docblock): a day with REAL clock events plus a covering novelty
     * anyway. justified_minutes stays 0 and justification_json stays null —
     * no proration rule is invented for a partially-worked day, and ordinary/
     * overtime/missing classification proceeds exactly as if the novelty
     * did not exist.
     */
    public function test_a_day_with_real_clock_events_and_a_covering_novelty_is_not_justified()
    {
        $this->ruleVersion(['tolerance_minutes' => 15, 'rounding_minutes' => 5]);
        $this->standardShift('2026-02-10');
        $this->event('clock_in', '2026-02-10 06:07:00');
        $this->event('break_start', '2026-02-10 12:00:00');
        $this->event('break_end', '2026-02-10 13:00:00');
        $this->event('clock_out', '2026-02-10 14:23:00');
        $this->approvedNoveltyCovering('2026-02-10');

        $record = $this->engine->calculateForDate($this->employee, Carbon::parse('2026-02-10'));

        // Same result as the docs example without any novelty at all.
        $this->assertSame(420, $record->ordinary_minutes);
        $this->assertSame(0, $record->overtime_candidate_minutes);
        $this->assertSame(0, $record->missing_minutes);
        $this->assertSame(0, $record->justified_minutes);
        $this->assertNull($record->justification_json);
    }

    public function test_no_active_labor_rule_version_still_throws_even_with_a_covering_novelty()
    {
        // Regression guard: an approved novelty must never bypass the
        // pre-existing NoActiveLaborRuleVersionException blocking behavior
        // from Fase 7 — the rule version is resolved before the novelty is
        // ever looked up.
        $this->standardShift('2026-02-10');
        $this->approvedNoveltyCovering('2026-02-10');

        $this->expectException(NoActiveLaborRuleVersionException::class);

        $this->engine->calculateForDate($this->employee, Carbon::parse('2026-02-10'));
    }

    public function test_a_detected_overtime_candidate_creates_a_single_detected_overtime_record()
    {
        $this->ruleVersion(['tolerance_minutes' => 15, 'rounding_minutes' => 1]);
        $shift = $this->standardShift('2026-02-10');
        $this->event('clock_in', '2026-02-10 06:07:00');
        $this->event('break_start', '2026-02-10 12:00:00');
        $this->event('break_end', '2026-02-10 13:00:00');
        $this->event('clock_out', '2026-02-10 14:23:00');

        $record = $this->engine->calculateForDate($this->employee, Carbon::parse('2026-02-10'));

        $this->assertSame(1, $record->overtime_candidate_minutes);
        $this->assertSame(1, OvertimeRecord::query()->count());

        $overtime = OvertimeRecord::query()->firstOrFail();
        $this->assertSame($this->employee->id, $overtime->employee_id);
        $this->assertSame($shift->id, $overtime->shift_id);
        $this->assertSame('detected', $overtime->status);
        $this->assertSame(1, $overtime->detected_minutes);
    }

    public function test_recalculating_the_same_date_updates_the_detected_overtime_record_without_duplicating_it()
    {
        $this->ruleVersion(['tolerance_minutes' => 15, 'rounding_minutes' => 1]);
        $this->standardShift('2026-02-10');
        $this->event('clock_in', '2026-02-10 06:07:00');
        $this->event('break_start', '2026-02-10 12:00:00');
        $this->event('break_end', '2026-02-10 13:00:00');
        $this->event('clock_out', '2026-02-10 14:23:00');

        $this->engine->calculateForDate($this->employee, Carbon::parse('2026-02-10'));
        $this->assertSame(1, OvertimeRecord::query()->count());
        $this->assertSame(1, OvertimeRecord::query()->firstOrFail()->detected_minutes);

        // A later clock_out raises the excess (see
        // test_rounding_is_applied_to_the_classified_minutes for the same
        // arithmetic): diff +27, excess over tolerance 15 = 12.
        $this->event('clock_out', '2026-02-10 14:34:00');
        $this->engine->calculateForDate($this->employee, Carbon::parse('2026-02-10'));

        $this->assertSame(1, OvertimeRecord::query()->count());
        $this->assertSame(12, OvertimeRecord::query()->firstOrFail()->detected_minutes);
    }

    /**
     * Roadmap-mandated acceptance test for Fase 8, exercised end-to-end
     * through the engine (not a factory-built `detected` row as in
     * OvertimeRecordServiceTest): an overtime record the engine itself
     * detected can never reach `paid` without going through
     * authorize() first.
     */
    public function test_an_engine_detected_overtime_record_never_reaches_paid_without_authorization()
    {
        $this->ruleVersion(['tolerance_minutes' => 15, 'rounding_minutes' => 1]);
        $this->standardShift('2026-02-10');
        $this->event('clock_in', '2026-02-10 06:07:00');
        $this->event('break_start', '2026-02-10 12:00:00');
        $this->event('break_end', '2026-02-10 13:00:00');
        $this->event('clock_out', '2026-02-10 14:23:00');

        $this->engine->calculateForDate($this->employee, Carbon::parse('2026-02-10'));
        $overtime = OvertimeRecord::query()->firstOrFail();
        $this->assertSame('detected', $overtime->status);

        app(CurrentCompany::class)->set($this->company);
        $service = app(OvertimeRecordService::class);
        $actor = User::factory()->create();

        $this->expectException(InvalidOvertimeRecordStatusException::class);

        $service->markPaid($overtime, $actor);
    }

    /**
     * A human decision (authorize()) must never be silently regressed by a
     * later engine recalculation for the same shift — see the engine's
     * docblock comment at the overtime upsert.
     */
    public function test_recalculation_never_regresses_an_already_authorized_overtime_record()
    {
        $this->ruleVersion(['tolerance_minutes' => 15, 'rounding_minutes' => 1]);
        $this->standardShift('2026-02-10');
        $this->event('clock_in', '2026-02-10 06:07:00');
        $this->event('break_start', '2026-02-10 12:00:00');
        $this->event('break_end', '2026-02-10 13:00:00');
        $this->event('clock_out', '2026-02-10 14:23:00');

        $this->engine->calculateForDate($this->employee, Carbon::parse('2026-02-10'));
        $detected = OvertimeRecord::query()->firstOrFail();

        app(CurrentCompany::class)->set($this->company);
        $service = app(OvertimeRecordService::class);
        $actor = User::factory()->create();
        $requested = $service->request($detected, $actor, 1);
        $authorized = $service->authorize($requested, $actor, 1);

        // A later clock_out would otherwise raise detected_minutes to 12
        // (see test_recalculating_the_same_date_updates_the_detected_overtime_record_without_duplicating_it).
        $this->event('clock_out', '2026-02-10 14:34:00');
        $this->engine->calculateForDate($this->employee, Carbon::parse('2026-02-10'));

        $authorized->refresh();
        $this->assertSame(1, OvertimeRecord::query()->count());
        $this->assertSame('authorized', $authorized->status);
        $this->assertSame(1, $authorized->authorized_minutes);
    }

    public function test_missing_clock_out_throws()
    {
        $this->ruleVersion(['tolerance_minutes' => 15, 'rounding_minutes' => 5]);
        $this->standardShift('2026-02-10');
        $this->event('clock_in', '2026-02-10 06:07:00');

        $this->expectException(MissingCriticalAttendanceEventException::class);

        $this->engine->calculateForDate($this->employee, Carbon::parse('2026-02-10'));
    }

    public function test_missing_clock_in_throws()
    {
        $this->ruleVersion(['tolerance_minutes' => 15, 'rounding_minutes' => 5]);
        $this->standardShift('2026-02-10');
        $this->event('clock_out', '2026-02-10 14:23:00');

        $this->expectException(MissingCriticalAttendanceEventException::class);

        $this->engine->calculateForDate($this->employee, Carbon::parse('2026-02-10'));
    }

    public function test_an_unmatched_break_start_is_not_subtracted_from_worked_time()
    {
        $this->ruleVersion(['tolerance_minutes' => 100, 'rounding_minutes' => 1]);
        $this->standardShift('2026-02-10');
        $this->event('clock_in', '2026-02-10 06:00:00');
        $this->event('break_start', '2026-02-10 12:00:00');
        // No break_end before clock_out.
        $this->event('clock_out', '2026-02-10 14:00:00');

        $record = $this->engine->calculateForDate($this->employee, Carbon::parse('2026-02-10'));

        // gross worked 06:00->14:00 = 480, no paired break subtracted -> 480.
        $this->assertSame(480, $record->worked_json['worked_minutes']);
    }

    public function test_no_labor_rule_at_all_throws_no_active_version_exception()
    {
        $this->standardShift('2026-02-10');
        $this->event('clock_in', '2026-02-10 06:07:00');
        $this->event('clock_out', '2026-02-10 14:23:00');

        $this->expectException(NoActiveLaborRuleVersionException::class);

        $this->engine->calculateForDate($this->employee, Carbon::parse('2026-02-10'));
    }

    public function test_no_active_version_for_the_date_throws()
    {
        $this->ruleVersion(['tolerance_minutes' => 15, 'rounding_minutes' => 5], '2027-01-01');
        $this->standardShift('2026-02-10');
        $this->event('clock_in', '2026-02-10 06:07:00');
        $this->event('clock_out', '2026-02-10 14:23:00');

        $this->expectException(NoActiveLaborRuleVersionException::class);

        $this->engine->calculateForDate($this->employee, Carbon::parse('2026-02-10'));
    }

    public function test_ambiguous_overlapping_versions_throws()
    {
        $this->ruleVersion(['tolerance_minutes' => 15, 'rounding_minutes' => 5], '2026-01-01', null);
        $this->ruleVersion(['tolerance_minutes' => 10, 'rounding_minutes' => 5], '2026-01-15', null);
        $this->standardShift('2026-02-10');
        $this->event('clock_in', '2026-02-10 06:07:00');
        $this->event('clock_out', '2026-02-10 14:23:00');

        $this->expectException(AmbiguousLaborRuleVersionException::class);

        $this->engine->calculateForDate($this->employee, Carbon::parse('2026-02-10'));
    }

    public function test_missing_tolerance_minutes_parameter_throws()
    {
        $this->ruleVersion(['rounding_minutes' => 5]);
        $this->standardShift('2026-02-10');
        $this->event('clock_in', '2026-02-10 06:07:00');
        $this->event('clock_out', '2026-02-10 14:23:00');

        $this->expectException(MissingLaborRuleParameterException::class);

        $this->engine->calculateForDate($this->employee, Carbon::parse('2026-02-10'));
    }

    public function test_missing_rounding_minutes_parameter_throws()
    {
        $this->ruleVersion(['tolerance_minutes' => 15]);
        $this->standardShift('2026-02-10');
        $this->event('clock_in', '2026-02-10 06:07:00');
        $this->event('clock_out', '2026-02-10 14:23:00');

        $this->expectException(MissingLaborRuleParameterException::class);

        $this->engine->calculateForDate($this->employee, Carbon::parse('2026-02-10'));
    }

    public function test_an_approved_modify_adjustment_changes_the_effective_clock_out_time()
    {
        $this->ruleVersion(['tolerance_minutes' => 100, 'rounding_minutes' => 1]);
        $this->standardShift('2026-02-10');
        $this->event('clock_in', '2026-02-10 06:00:00');
        $this->event('break_start', '2026-02-10 12:00:00');
        $this->event('break_end', '2026-02-10 13:00:00');
        $rawClockOut = $this->event('clock_out', '2026-02-10 14:00:00');

        AttendanceAdjustment::factory()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'original_event_id' => $rawClockOut->id,
            'type' => 'modify',
            'corrected_value' => ['event_datetime' => '2026-02-10 15:00:00'],
            'status' => 'approved',
        ]);

        $record = $this->engine->calculateForDate($this->employee, Carbon::parse('2026-02-10'));

        // gross worked becomes 06:00->15:00 = 540, minus 60 break = 480.
        $this->assertSame(480, $record->worked_json['worked_minutes']);
        $this->assertSame(
            Carbon::parse('2026-02-10 15:00:00')->toIso8601String(),
            $record->worked_json['clock_out'],
        );
    }

    public function test_an_approved_invalidate_adjustment_that_removes_the_only_clock_out_triggers_missing_event()
    {
        $this->ruleVersion(['tolerance_minutes' => 15, 'rounding_minutes' => 5]);
        $this->standardShift('2026-02-10');
        $this->event('clock_in', '2026-02-10 06:07:00');
        $rawClockOut = $this->event('clock_out', '2026-02-10 14:23:00');

        AttendanceAdjustment::factory()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'original_event_id' => $rawClockOut->id,
            'type' => 'invalidate',
            'corrected_value' => ['reason_code' => 'not_a_real_marking'],
            'status' => 'approved',
        ]);

        $this->expectException(MissingCriticalAttendanceEventException::class);

        $this->engine->calculateForDate($this->employee, Carbon::parse('2026-02-10'));
    }

    public function test_calculate_for_range_does_not_abort_on_one_blocked_date()
    {
        $this->ruleVersion(['tolerance_minutes' => 15, 'rounding_minutes' => 5]);

        // Day 1: valid.
        $this->standardShift('2026-02-10');
        $this->event('clock_in', '2026-02-10 06:07:00');
        $this->event('clock_out', '2026-02-10 14:23:00');

        // Day 2: shift assigned, clock_in only -> blocked.
        $this->standardShift('2026-02-11');
        $this->event('clock_in', '2026-02-11 06:00:00');

        // Day 3: valid, full absence.
        $this->standardShift('2026-02-12');

        $results = $this->engine->calculateForRange(
            $this->employee,
            Carbon::parse('2026-02-10'),
            Carbon::parse('2026-02-12'),
        );

        $byDate = $results->keyBy('date');

        $this->assertSame('ok', $byDate['2026-02-10']['status']);
        $this->assertNotNull($byDate['2026-02-10']['record']);

        $this->assertSame('blocked', $byDate['2026-02-11']['status']);
        $this->assertNull($byDate['2026-02-11']['record']);
        $this->assertNotEmpty($byDate['2026-02-11']['error']);

        $this->assertSame('ok', $byDate['2026-02-12']['status']);
        $this->assertNotNull($byDate['2026-02-12']['record']);
    }

    public function test_calculating_the_same_date_twice_regenerates_a_single_record()
    {
        $this->ruleVersion(['tolerance_minutes' => 15, 'rounding_minutes' => 5]);
        $this->standardShift('2026-02-10');
        $this->event('clock_in', '2026-02-10 06:07:00');
        $this->event('clock_out', '2026-02-10 14:23:00');

        $first = $this->engine->calculateForDate($this->employee, Carbon::parse('2026-02-10'));
        $this->event('clock_out', '2026-02-10 15:00:00');
        $second = $this->engine->calculateForDate($this->employee, Carbon::parse('2026-02-10'));

        $this->assertSame(1, AttendanceRecord::query()->count());
        $this->assertSame($first->id, $second->id);
        $this->assertNotSame($first->worked_json['worked_minutes'], $second->worked_json['worked_minutes']);
    }

    public function test_attendance_record_persisted_attributes_carry_no_money_or_percentage_shaped_fields()
    {
        $this->ruleVersion(['tolerance_minutes' => 15, 'rounding_minutes' => 5]);
        $this->standardShift('2026-02-10');
        $this->event('clock_in', '2026-02-10 06:07:00');
        $this->event('clock_out', '2026-02-10 14:23:00');

        $record = $this->engine->calculateForDate($this->employee, Carbon::parse('2026-02-10'));

        $expectedTimeMagnitudeFields = [
            'company_id',
            'employee_id',
            'date',
            'planned_json',
            'worked_json',
            'ordinary_minutes',
            'overtime_candidate_minutes',
            'missing_minutes',
            'justified_minutes',
            'justification_json',
            'rule_version_id',
            'calculated_at',
        ];

        $this->assertSame($expectedTimeMagnitudeFields, $record->getFillable());

        foreach ($record->getFillable() as $field) {
            $this->assertStringNotContainsStringIgnoringCase('money', $field);
            $this->assertStringNotContainsStringIgnoringCase('amount', $field);
            $this->assertStringNotContainsStringIgnoringCase('percentage', $field);
            $this->assertStringNotContainsStringIgnoringCase('rate', $field);
        }
    }

    public function test_time_calculation_run_is_written_alongside_the_attendance_record()
    {
        $this->ruleVersion(['tolerance_minutes' => 15, 'rounding_minutes' => 5]);
        $this->standardShift('2026-02-10');
        $this->event('clock_in', '2026-02-10 06:07:00');
        $this->event('clock_out', '2026-02-10 14:23:00');

        $record = $this->engine->calculateForDate($this->employee, Carbon::parse('2026-02-10'));

        $run = TimeCalculationRun::query()->where('output_ref', $record->id)->first();

        $this->assertNotNull($run);
        $this->assertSame($this->employee->id, $run->employee_id);
        $this->assertNotEmpty($run->inputs_hash);
    }
}
