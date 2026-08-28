<?php

namespace Tests\Unit;

use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeSchedule;
use App\Models\Shift;
use App\Models\WorkScheduleDay;
use App\Models\WorkScheduleTemplate;
use App\Services\Shifts\ShiftGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ShiftGeneratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_generates_a_shift_per_matching_day_of_week_in_the_range()
    {
        $company = Company::factory()->create();
        $employee = Employee::factory()->create(['company_id' => $company->id]);
        $template = WorkScheduleTemplate::factory()->create(['company_id' => $company->id]);

        // Monday (1) 06:00-14:00.
        WorkScheduleDay::factory()->create([
            'company_id' => $company->id,
            'template_id' => $template->id,
            'day_of_week' => 1,
            'start_time' => '06:00:00',
            'end_time' => '14:00:00',
            'crosses_midnight' => false,
        ]);

        EmployeeSchedule::factory()->create([
            'company_id' => $company->id,
            'employee_id' => $employee->id,
            'template_id' => $template->id,
            'effective_from' => '2026-01-01',
            'effective_to' => null,
        ]);

        // 2026-01-05 through 2026-01-11 contains exactly one Monday (Jan 5).
        $generated = (new ShiftGenerator)->generate(
            $employee,
            Carbon::parse('2026-01-05'),
            Carbon::parse('2026-01-11'),
        );

        $this->assertCount(1, $generated);

        $shift = $generated->first();
        $this->assertSame('2026-01-05', $shift->date->toDateString());
        $this->assertSame('2026-01-05 06:00:00', $shift->start_datetime->toDateTimeString());
        $this->assertSame('2026-01-05 14:00:00', $shift->end_datetime->toDateTimeString());
        $this->assertFalse((bool) $shift->crosses_midnight);
        $this->assertSame('template', $shift->source);
        $this->assertSame(1, $shift->assignments()->count());
        $this->assertSame($employee->id, $shift->assignments()->first()->employee_id);
    }

    public function test_it_produces_a_night_shift_with_end_datetime_on_the_next_calendar_day()
    {
        $company = Company::factory()->create();
        $employee = Employee::factory()->create(['company_id' => $company->id]);
        $template = WorkScheduleTemplate::factory()->create(['company_id' => $company->id]);

        // Tuesday (2) 22:00 -> 06:00 the next day.
        WorkScheduleDay::factory()->create([
            'company_id' => $company->id,
            'template_id' => $template->id,
            'day_of_week' => 2,
            'start_time' => '22:00:00',
            'end_time' => '06:00:00',
            'crosses_midnight' => true,
        ]);

        EmployeeSchedule::factory()->create([
            'company_id' => $company->id,
            'employee_id' => $employee->id,
            'template_id' => $template->id,
            'effective_from' => '2026-01-01',
        ]);

        $generated = (new ShiftGenerator)->generate($employee, Carbon::parse('2026-01-06'), Carbon::parse('2026-01-06'));

        $shift = $generated->sole();

        $this->assertSame('2026-01-06', $shift->date->toDateString());
        $this->assertSame('2026-01-06 22:00:00', $shift->start_datetime->toDateTimeString());
        $this->assertSame('2026-01-07 06:00:00', $shift->end_datetime->toDateTimeString());
        $this->assertTrue((bool) $shift->crosses_midnight);
    }

    public function test_it_creates_a_planned_break_when_the_template_day_defines_one()
    {
        $company = Company::factory()->create();
        $employee = Employee::factory()->create(['company_id' => $company->id]);
        $template = WorkScheduleTemplate::factory()->create(['company_id' => $company->id]);

        // Monday (1) 06:00-14:00 with a 12:00-13:00 planned lunch break.
        WorkScheduleDay::factory()->create([
            'company_id' => $company->id,
            'template_id' => $template->id,
            'day_of_week' => 1,
            'start_time' => '06:00:00',
            'end_time' => '14:00:00',
            'crosses_midnight' => false,
            'break_start_time' => '12:00:00',
            'break_end_time' => '13:00:00',
        ]);

        EmployeeSchedule::factory()->create([
            'company_id' => $company->id,
            'employee_id' => $employee->id,
            'template_id' => $template->id,
            'effective_from' => '2026-01-01',
        ]);

        $generated = (new ShiftGenerator)->generate(
            $employee,
            Carbon::parse('2026-01-05'),
            Carbon::parse('2026-01-05'),
        );

        $shift = $generated->sole();

        $this->assertSame(1, $shift->breaks()->count());

        $break = $shift->breaks()->sole();
        $this->assertSame('2026-01-05 12:00:00', $break->planned_start->toDateTimeString());
        $this->assertSame('2026-01-05 13:00:00', $break->planned_end->toDateTimeString());
        $this->assertFalse((bool) $break->paid);
    }

    public function test_it_does_not_create_a_break_when_the_template_day_has_no_break_window()
    {
        $company = Company::factory()->create();
        $employee = Employee::factory()->create(['company_id' => $company->id]);
        $template = WorkScheduleTemplate::factory()->create(['company_id' => $company->id]);

        WorkScheduleDay::factory()->create([
            'company_id' => $company->id,
            'template_id' => $template->id,
            'day_of_week' => 1,
            'start_time' => '06:00:00',
            'end_time' => '14:00:00',
            'crosses_midnight' => false,
        ]);

        EmployeeSchedule::factory()->create([
            'company_id' => $company->id,
            'employee_id' => $employee->id,
            'template_id' => $template->id,
            'effective_from' => '2026-01-01',
        ]);

        $generated = (new ShiftGenerator)->generate(
            $employee,
            Carbon::parse('2026-01-05'),
            Carbon::parse('2026-01-05'),
        );

        $shift = $generated->sole();

        $this->assertSame(0, $shift->breaks()->count());
    }

    public function test_it_creates_a_break_with_correct_absolute_datetimes_on_a_crosses_midnight_day()
    {
        $company = Company::factory()->create();
        $employee = Employee::factory()->create(['company_id' => $company->id]);
        $template = WorkScheduleTemplate::factory()->create(['company_id' => $company->id]);

        // Tuesday (2) 22:00 -> 06:00 the next day, with a 00:30-01:00 break
        // that itself falls after the midnight crossing.
        WorkScheduleDay::factory()->create([
            'company_id' => $company->id,
            'template_id' => $template->id,
            'day_of_week' => 2,
            'start_time' => '22:00:00',
            'end_time' => '06:00:00',
            'crosses_midnight' => true,
            'break_start_time' => '00:30:00',
            'break_end_time' => '01:00:00',
        ]);

        EmployeeSchedule::factory()->create([
            'company_id' => $company->id,
            'employee_id' => $employee->id,
            'template_id' => $template->id,
            'effective_from' => '2026-01-01',
        ]);

        $generated = (new ShiftGenerator)->generate($employee, Carbon::parse('2026-01-06'), Carbon::parse('2026-01-06'));

        $shift = $generated->sole();
        $break = $shift->breaks()->sole();

        $this->assertSame('2026-01-07 00:30:00', $break->planned_start->toDateTimeString());
        $this->assertSame('2026-01-07 01:00:00', $break->planned_end->toDateTimeString());
    }

    public function test_it_skips_dates_with_no_active_schedule_without_erroring()
    {
        $company = Company::factory()->create();
        $employee = Employee::factory()->create(['company_id' => $company->id]);

        $generated = (new ShiftGenerator)->generate($employee, Carbon::parse('2026-01-01'), Carbon::parse('2026-01-07'));

        $this->assertCount(0, $generated);
        $this->assertSame(0, Shift::query()->count());
    }

    public function test_regenerating_an_already_covered_range_does_not_create_duplicates()
    {
        $company = Company::factory()->create();
        $employee = Employee::factory()->create(['company_id' => $company->id]);
        $template = WorkScheduleTemplate::factory()->create(['company_id' => $company->id]);

        WorkScheduleDay::factory()->create([
            'company_id' => $company->id,
            'template_id' => $template->id,
            'day_of_week' => 1,
            'start_time' => '06:00:00',
            'end_time' => '14:00:00',
        ]);

        EmployeeSchedule::factory()->create([
            'company_id' => $company->id,
            'employee_id' => $employee->id,
            'template_id' => $template->id,
            'effective_from' => '2026-01-01',
        ]);

        $generator = new ShiftGenerator;
        $generator->generate($employee, Carbon::parse('2026-01-05'), Carbon::parse('2026-01-05'));
        $secondRun = $generator->generate($employee, Carbon::parse('2026-01-05'), Carbon::parse('2026-01-05'));

        $this->assertCount(0, $secondRun);
        $this->assertSame(1, Shift::query()->count());
    }
}
