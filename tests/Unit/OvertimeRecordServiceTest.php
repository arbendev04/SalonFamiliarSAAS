<?php

namespace Tests\Unit;

use App\Exceptions\InvalidOvertimeRecordStatusException;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Employee;
use App\Models\OvertimeRecord;
use App\Models\Shift;
use App\Models\User;
use App\Services\Overtime\OvertimeRecordService;
use App\Services\Tenancy\CurrentCompany;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * No OvertimeRecordController exists yet (deferred to a later commit), so
 * this exercises the service directly rather than through HTTP routes —
 * same convention as LeaveRecordServiceTest. CurrentCompany is set manually
 * (no SetCurrentCompany middleware to run it for us).
 *
 * `detected` rows are constructed directly via the factory, never via
 * TimeCalculationEngine (Fase 8, section D — out of scope for this commit).
 */
class OvertimeRecordServiceTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Employee $employee;

    private User $actor;

    private OvertimeRecordService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        app(CurrentCompany::class)->set($this->company);

        $this->employee = Employee::factory()->create(['company_id' => $this->company->id]);
        $this->actor = User::factory()->create();
        $this->service = app(OvertimeRecordService::class);
    }

    /**
     * Creates a fresh detected record on its own new Shift each time (the
     * unique constraint is on (employee_id, shift_id), so reusing a single
     * shift across multiple records in the same test would collide).
     */
    private function detectedRecord(): OvertimeRecord
    {
        return OvertimeRecord::factory()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'shift_id' => Shift::factory()->create(['company_id' => $this->company->id])->id,
            'detected_minutes' => 45,
        ]);
    }

    public function test_the_full_happy_path_moves_detected_through_requested_authorized_to_paid()
    {
        $record = $this->detectedRecord();
        $this->assertSame('detected', $record->status);

        $requested = $this->service->request($record, $this->actor, 40);
        $this->assertSame('requested', $requested->status);
        $this->assertSame(40, $requested->requested_minutes);

        $authorized = $this->service->authorize($requested, $this->actor, 35);
        $this->assertSame('authorized', $authorized->status);
        $this->assertSame(35, $authorized->authorized_minutes);

        $paid = $this->service->markPaid($authorized, $this->actor);
        $this->assertSame('paid', $paid->status);

        $this->assertSame(
            1,
            AuditLog::query()->where('entity_id', $record->id)->where('action', 'overtime_record.requested')->count(),
        );
        $this->assertSame(
            1,
            AuditLog::query()->where('entity_id', $record->id)->where('action', 'overtime_record.authorized')->count(),
        );
        $this->assertSame(
            1,
            AuditLog::query()->where('entity_id', $record->id)->where('action', 'overtime_record.paid')->count(),
        );
    }

    public function test_request_on_a_non_detected_record_throws()
    {
        $record = $this->detectedRecord();
        $requested = $this->service->request($record, $this->actor, 40);

        $this->expectException(InvalidOvertimeRecordStatusException::class);

        $this->service->request($requested, $this->actor, 40);
    }

    public function test_authorize_on_a_non_requested_record_throws()
    {
        $record = $this->detectedRecord();

        $this->expectException(InvalidOvertimeRecordStatusException::class);

        $this->service->authorize($record, $this->actor, 30);
    }

    public function test_reject_on_a_non_requested_record_throws()
    {
        $record = $this->detectedRecord();

        $this->expectException(InvalidOvertimeRecordStatusException::class);

        $this->service->reject($record, $this->actor);
    }

    /**
     * Roadmap-mandated acceptance test: "una hora extra no autorizada no se
     * traduce en pago" — markPaid() is only reachable via
     * authorize()->markPaid(), no other path. Covers both a fresh `detected`
     * record and a `requested`-but-not-yet-authorized record.
     */
    public function test_overtime_cannot_be_marked_paid_without_authorization()
    {
        $detected = $this->detectedRecord();

        try {
            $this->service->markPaid($detected, $this->actor);
            $this->fail('Expected InvalidOvertimeRecordStatusException for a detected record.');
        } catch (InvalidOvertimeRecordStatusException) {
            // expected
        }

        $requested = $this->service->request($this->detectedRecord(), $this->actor, 20);

        $this->expectException(InvalidOvertimeRecordStatusException::class);

        $this->service->markPaid($requested, $this->actor);
    }

    public function test_reject_on_requested_transitions_to_rejected_and_can_never_reach_paid()
    {
        $record = $this->service->request($this->detectedRecord(), $this->actor, 30);

        $rejected = $this->service->reject($record, $this->actor);

        $this->assertSame('rejected', $rejected->status);
        $this->assertSame(
            1,
            AuditLog::query()->where('entity_id', $record->id)->where('action', 'overtime_record.rejected')->count(),
        );

        $this->expectException(InvalidOvertimeRecordStatusException::class);

        $this->service->markPaid($rejected, $this->actor);
    }

    public function test_a_second_overtime_record_for_the_same_employee_and_shift_violates_the_unique_constraint()
    {
        $shift = Shift::factory()->create(['company_id' => $this->company->id]);

        OvertimeRecord::factory()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'shift_id' => $shift->id,
        ]);

        $this->expectException(QueryException::class);

        OvertimeRecord::factory()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'shift_id' => $shift->id,
        ]);
    }
}
