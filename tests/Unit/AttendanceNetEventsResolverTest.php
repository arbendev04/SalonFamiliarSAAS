<?php

namespace Tests\Unit;

use App\Models\AttendanceAdjustment;
use App\Models\AttendanceEvent;
use App\Models\Company;
use App\Models\Employee;
use App\Services\TimeCalculation\AttendanceNetEventsResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AttendanceNetEventsResolverTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Employee $employee;

    private AttendanceNetEventsResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->employee = Employee::factory()->create(['company_id' => $this->company->id]);
        $this->resolver = new AttendanceNetEventsResolver;
    }

    private function windowStart(): Carbon
    {
        return Carbon::parse('2026-02-10 00:00:00');
    }

    private function windowEnd(): Carbon
    {
        return Carbon::parse('2026-02-10 23:59:59');
    }

    /**
     * Sets a precise created_at on an already-persisted adjustment, since
     * the "latest wins" ordering depends on it and the factory silently
     * discards created_at (it is not in $fillable, and mass-assignment
     * guarding is not disabled for AttendanceAdjustment).
     */
    private function backdateAdjustment(AttendanceAdjustment $adjustment, Carbon $createdAt): void
    {
        $adjustment->forceFill(['created_at' => $createdAt])->save();
    }

    public function test_events_with_no_adjustments_pass_through_unchanged_in_order()
    {
        $clockOut = AttendanceEvent::factory()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'event_type' => 'clock_out',
            'event_datetime' => '2026-02-10 17:00:00',
        ]);

        $clockIn = AttendanceEvent::factory()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'event_type' => 'clock_in',
            'event_datetime' => '2026-02-10 08:00:00',
        ]);

        $resolved = $this->resolver->resolve($this->employee, $this->windowStart(), $this->windowEnd());

        $this->assertSame(2, $resolved->count());
        $this->assertSame([$clockIn->id, $clockOut->id], $resolved->pluck('event_id')->all());
        $this->assertSame(['clock_in', 'clock_out'], $resolved->pluck('event_type')->all());
        $this->assertTrue($resolved->first()['event_datetime']->equalTo(Carbon::parse('2026-02-10 08:00:00')));
    }

    public function test_an_approved_invalidate_adjustment_excludes_its_target_event()
    {
        $keptEvent = AttendanceEvent::factory()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'event_type' => 'clock_in',
            'event_datetime' => '2026-02-10 08:00:00',
        ]);

        $invalidatedEvent = AttendanceEvent::factory()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'event_type' => 'clock_out',
            'event_datetime' => '2026-02-10 17:00:00',
        ]);

        AttendanceAdjustment::factory()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'original_event_id' => $invalidatedEvent->id,
            'type' => 'invalidate',
            'corrected_value' => ['reason_code' => 'not_a_real_marking'],
            'status' => 'approved',
        ]);

        $resolved = $this->resolver->resolve($this->employee, $this->windowStart(), $this->windowEnd());

        $this->assertSame(1, $resolved->count());
        $this->assertSame([$keptEvent->id], $resolved->pluck('event_id')->all());

        // The underlying attendance_events row itself is untouched.
        $this->assertDatabaseHas('attendance_events', [
            'id' => $invalidatedEvent->id,
            'event_type' => 'clock_out',
            'event_datetime' => '2026-02-10 17:00:00',
        ]);
    }

    public function test_modify_with_only_event_datetime_keeps_the_original_event_type()
    {
        $event = AttendanceEvent::factory()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'event_type' => 'clock_in',
            'event_datetime' => '2026-02-10 08:00:00',
        ]);

        AttendanceAdjustment::factory()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'original_event_id' => $event->id,
            'type' => 'modify',
            'corrected_value' => ['event_datetime' => '2026-02-10 08:05:00'],
            'status' => 'approved',
        ]);

        $resolved = $this->resolver->resolve($this->employee, $this->windowStart(), $this->windowEnd());

        $this->assertSame(1, $resolved->count());
        $resolvedEvent = $resolved->first();
        $this->assertSame('clock_in', $resolvedEvent['event_type']);
        $this->assertTrue($resolvedEvent['event_datetime']->equalTo(Carbon::parse('2026-02-10 08:05:00')));
    }

    public function test_modify_with_only_event_type_keeps_the_original_datetime()
    {
        $event = AttendanceEvent::factory()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'event_type' => 'break_end',
            'event_datetime' => '2026-02-10 09:00:00',
        ]);

        AttendanceAdjustment::factory()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'original_event_id' => $event->id,
            'type' => 'modify',
            'corrected_value' => ['event_type' => 'break_start'],
            'status' => 'approved',
        ]);

        $resolved = $this->resolver->resolve($this->employee, $this->windowStart(), $this->windowEnd());

        $this->assertSame(1, $resolved->count());
        $resolvedEvent = $resolved->first();
        $this->assertSame('break_start', $resolvedEvent['event_type']);
        $this->assertTrue($resolvedEvent['event_datetime']->equalTo(Carbon::parse('2026-02-10 09:00:00')));
    }

    public function test_modify_with_both_keys_overrides_both()
    {
        $event = AttendanceEvent::factory()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'event_type' => 'clock_in',
            'event_datetime' => '2026-02-10 08:00:00',
        ]);

        AttendanceAdjustment::factory()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'original_event_id' => $event->id,
            'type' => 'modify',
            'corrected_value' => ['event_type' => 'clock_out', 'event_datetime' => '2026-02-10 08:10:00'],
            'status' => 'approved',
        ]);

        $resolved = $this->resolver->resolve($this->employee, $this->windowStart(), $this->windowEnd());

        $resolvedEvent = $resolved->first();
        $this->assertSame('clock_out', $resolvedEvent['event_type']);
        $this->assertTrue($resolvedEvent['event_datetime']->equalTo(Carbon::parse('2026-02-10 08:10:00')));
    }

    public function test_two_approved_adjustments_on_the_same_event_only_the_most_recent_applies()
    {
        $event = AttendanceEvent::factory()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'event_type' => 'clock_in',
            'event_datetime' => '2026-02-10 08:00:00',
        ]);

        $olderAdjustment = AttendanceAdjustment::factory()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'original_event_id' => $event->id,
            'type' => 'modify',
            'corrected_value' => ['event_datetime' => '2026-02-10 08:05:00'],
            'status' => 'approved',
        ]);
        $this->backdateAdjustment($olderAdjustment, Carbon::parse('2026-02-11 09:00:00'));

        $newerAdjustment = AttendanceAdjustment::factory()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'original_event_id' => $event->id,
            'type' => 'invalidate',
            'corrected_value' => ['reason_code' => 'not_a_real_marking'],
            'status' => 'approved',
        ]);
        $this->backdateAdjustment($newerAdjustment, Carbon::parse('2026-02-11 10:00:00'));

        $resolved = $this->resolver->resolve($this->employee, $this->windowStart(), $this->windowEnd());

        // The newer (invalidate) adjustment wins entirely: the event is
        // excluded, never merged with the older modify's corrected_value.
        $this->assertSame(0, $resolved->count());
    }

    public function test_a_pending_adjustment_has_no_effect()
    {
        $event = AttendanceEvent::factory()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'event_type' => 'clock_in',
            'event_datetime' => '2026-02-10 08:00:00',
        ]);

        AttendanceAdjustment::factory()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'original_event_id' => $event->id,
            'type' => 'modify',
            'corrected_value' => ['event_datetime' => '2026-02-10 09:00:00'],
            'status' => 'pending',
        ]);

        $resolved = $this->resolver->resolve($this->employee, $this->windowStart(), $this->windowEnd());

        $this->assertSame(1, $resolved->count());
        $resolvedEvent = $resolved->first();
        $this->assertSame('clock_in', $resolvedEvent['event_type']);
        $this->assertTrue($resolvedEvent['event_datetime']->equalTo(Carbon::parse('2026-02-10 08:00:00')));
    }

    public function test_a_modify_that_reorders_events_comes_back_correctly_sorted()
    {
        $firstEvent = AttendanceEvent::factory()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'event_type' => 'clock_in',
            'event_datetime' => '2026-02-10 08:00:00',
        ]);

        $secondEvent = AttendanceEvent::factory()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'event_type' => 'break_start',
            'event_datetime' => '2026-02-10 09:00:00',
        ]);

        // Push the first event's corrected time past the second event's
        // original time, so their relative order flips.
        AttendanceAdjustment::factory()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'original_event_id' => $firstEvent->id,
            'type' => 'modify',
            'corrected_value' => ['event_datetime' => '2026-02-10 10:00:00'],
            'status' => 'approved',
        ]);

        $resolved = $this->resolver->resolve($this->employee, $this->windowStart(), $this->windowEnd());

        $this->assertSame([$secondEvent->id, $firstEvent->id], $resolved->pluck('event_id')->all());
        $this->assertSame([0, 1], $resolved->keys()->all());
    }

    public function test_events_outside_the_window_are_excluded_even_with_an_adjustment()
    {
        $outsideEvent = AttendanceEvent::factory()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'event_type' => 'clock_in',
            'event_datetime' => '2026-02-09 23:00:00',
        ]);

        AttendanceAdjustment::factory()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'original_event_id' => $outsideEvent->id,
            'type' => 'modify',
            'corrected_value' => ['event_datetime' => '2026-02-10 08:00:00'],
            'status' => 'approved',
        ]);

        $resolved = $this->resolver->resolve($this->employee, $this->windowStart(), $this->windowEnd());

        $this->assertSame(0, $resolved->count());
    }

    public function test_an_approved_add_adjustments_materialized_event_shows_up_like_any_other_raw_event()
    {
        $addAdjustment = AttendanceAdjustment::factory()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'original_event_id' => null,
            'type' => 'add',
            'corrected_value' => ['event_type' => 'clock_out', 'event_datetime' => '2026-02-10 17:05:00'],
            'status' => 'approved',
        ]);

        // Prove the "already materialized" claim empirically instead of
        // assuming it: insert the real attendance_events row the exact way
        // AttendanceAdjustmentService::insertEventForAddAdjustment() does.
        $insertedEvent = AttendanceEvent::factory()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'event_type' => 'clock_out',
            'event_datetime' => '2026-02-10 17:05:00',
            'source' => 'manual',
            'metadata' => ['created_from_adjustment_id' => $addAdjustment->id],
        ]);

        $resolved = $this->resolver->resolve($this->employee, $this->windowStart(), $this->windowEnd());

        $this->assertSame(1, $resolved->count());
        $this->assertSame($insertedEvent->id, $resolved->first()['event_id']);
        $this->assertSame('clock_out', $resolved->first()['event_type']);
    }
}
