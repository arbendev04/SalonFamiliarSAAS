<?php

namespace Tests\Unit;

use App\Models\Company;
use App\Models\Employee;
use App\Models\NoveltyRecord;
use App\Services\TimeCalculation\NoveltyRecordLookup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class NoveltyRecordLookupTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Employee $employee;

    private NoveltyRecordLookup $lookup;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->employee = Employee::factory()->create(['company_id' => $this->company->id]);
        $this->lookup = new NoveltyRecordLookup;
    }

    /**
     * Sets a precise created_at on an already-persisted novelty record,
     * since the "latest wins" tie-break depends on it and the factory
     * silently discards created_at (it is not in $fillable, and
     * mass-assignment guarding is not disabled for NoveltyRecord).
     */
    private function backdateNoveltyRecord(NoveltyRecord $record, Carbon $createdAt): void
    {
        $record->forceFill(['created_at' => $createdAt])->save();
    }

    public function test_an_approved_novelty_covering_the_date_is_returned()
    {
        $novelty = NoveltyRecord::factory()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'status' => 'approved',
            'date_from' => '2026-02-10',
            'date_to' => '2026-02-14',
        ]);

        $resolved = $this->lookup->resolve($this->employee, Carbon::parse('2026-02-12'));

        $this->assertNotNull($resolved);
        $this->assertSame($novelty->id, $resolved->id);
    }

    public function test_an_approved_novelty_not_covering_the_date_returns_null()
    {
        NoveltyRecord::factory()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'status' => 'approved',
            'date_from' => '2026-02-10',
            'date_to' => '2026-02-14',
        ]);

        $resolved = $this->lookup->resolve($this->employee, Carbon::parse('2026-02-20'));

        $this->assertNull($resolved);
    }

    public function test_a_pending_novelty_covering_the_date_returns_null()
    {
        NoveltyRecord::factory()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'status' => 'pending',
            'date_from' => '2026-02-10',
            'date_to' => '2026-02-14',
        ]);

        $resolved = $this->lookup->resolve($this->employee, Carbon::parse('2026-02-12'));

        $this->assertNull($resolved);
    }

    public function test_a_rejected_novelty_returns_null()
    {
        NoveltyRecord::factory()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'status' => 'rejected',
            'date_from' => '2026-02-10',
            'date_to' => '2026-02-14',
        ]);

        $resolved = $this->lookup->resolve($this->employee, Carbon::parse('2026-02-12'));

        $this->assertNull($resolved);
    }

    public function test_a_novelty_belonging_to_a_different_employee_returns_null()
    {
        $otherEmployee = Employee::factory()->create(['company_id' => $this->company->id]);

        NoveltyRecord::factory()->create([
            'company_id' => $this->company->id,
            'employee_id' => $otherEmployee->id,
            'status' => 'approved',
            'date_from' => '2026-02-10',
            'date_to' => '2026-02-14',
        ]);

        $resolved = $this->lookup->resolve($this->employee, Carbon::parse('2026-02-12'));

        $this->assertNull($resolved);
    }

    public function test_a_single_day_novelty_covers_its_own_date_exactly()
    {
        $novelty = NoveltyRecord::factory()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'status' => 'approved',
            'date_from' => '2026-02-10',
            'date_to' => '2026-02-10',
        ]);

        $resolved = $this->lookup->resolve($this->employee, Carbon::parse('2026-02-10'));

        $this->assertNotNull($resolved);
        $this->assertSame($novelty->id, $resolved->id);
    }

    /**
     * Nothing in the codebase today (no Postgres constraint, no
     * StoreLeaveRecordRequest validation) prevents two APPROVED
     * leave_records for the same employee with overlapping date ranges —
     * unlike employment_contracts (EXCLUDE USING gist + request-level
     * fallback) or ShiftAssignment::overlapsForEmployee(). So two APPROVED
     * novelty_records can legitimately cover the same employee/date.
     * Mirroring the exact precedent in AttendanceNetEventsResolver / the
     * PENDING DECISION at 07-ATTENDANCE.md (Flujo 2, punto 4): the most
     * recently created APPROVED novelty wins, never a merge.
     */
    public function test_two_overlapping_approved_novelties_the_most_recently_created_wins()
    {
        $older = NoveltyRecord::factory()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'status' => 'approved',
            'date_from' => '2026-02-10',
            'date_to' => '2026-02-14',
        ]);
        $this->backdateNoveltyRecord($older, Carbon::parse('2026-02-01 09:00:00'));

        $newer = NoveltyRecord::factory()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'status' => 'approved',
            'date_from' => '2026-02-12',
            'date_to' => '2026-02-16',
        ]);
        $this->backdateNoveltyRecord($newer, Carbon::parse('2026-02-05 09:00:00'));

        $resolved = $this->lookup->resolve($this->employee, Carbon::parse('2026-02-13'));

        $this->assertNotNull($resolved);
        $this->assertSame($newer->id, $resolved->id);
    }
}
