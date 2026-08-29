<?php

namespace App\Services\Leave;

use App\Exceptions\InvalidLeaveRecordStatusException;
use App\Exceptions\MissingNoveltyTypeForLeaveTypeException;
use App\Models\AbsenceRecord;
use App\Models\Employee;
use App\Models\LeaveRecord;
use App\Models\LeaveType;
use App\Models\NoveltyRecord;
use App\Models\NoveltyType;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\TimeCalculation\TimeCalculationEngine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The lifecycle for leave_records (Fase 8, section B of the plan): create,
 * approve, reject. Mirrors the exact template of
 * App\Services\Attendance\AttendanceAdjustmentService — constructor-injected
 * collaborators, each public method wrapped in one DB::transaction() with
 * AuditLogger::record() called inside that same transaction (ADR-018), and
 * auto-approval derived live from the requester's own RBAC grant rather than
 * a hardcoded role list (ADR-032).
 *
 * Per .ai/04-DOMAIN-MODEL.md "Contradicción #2", a novelty_records row
 * generated from an approved leave never runs its own approval flow — its
 * status simply mirrors the leave_records row that produced it. That
 * generation (plus the one-row-per-date absence_records cascade, only when
 * the resolved novelty_type affects time calculation) happens inside the
 * same transaction as the status write, both on auto-approved create() and
 * on approve().
 */
class LeaveRecordService
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly TimeCalculationEngine $timeCalculationEngine,
    ) {}

    public function create(
        Employee $employee,
        User $requestedBy,
        LeaveType $leaveType,
        Carbon $dateFrom,
        Carbon $dateTo,
        string $reason,
        ?string $documentRef = null,
    ): LeaveRecord {
        $record = DB::transaction(function () use ($employee, $requestedBy, $leaveType, $dateFrom, $dateTo, $reason, $documentRef) {
            // ADR-032: auto-approval is derived directly from the
            // requester's own RBAC grant, never from a role name hardcoded
            // here, so this can never drift out of sync with RoleSeeder.
            $autoApproved = $requestedBy->hasPermission('leave.approve');
            $status = $autoApproved ? 'approved' : 'pending';

            $record = LeaveRecord::create([
                'company_id' => $employee->company_id,
                'employee_id' => $employee->id,
                'leave_type_id' => $leaveType->id,
                'date_from' => $dateFrom->toDateString(),
                'date_to' => $dateTo->toDateString(),
                'status' => $status,
                'approved_by' => $autoApproved ? $requestedBy->id : null,
                'document_ref' => $documentRef,
                'reason' => $reason,
            ]);

            if ($autoApproved) {
                $this->generateNoveltyAndAbsence($record);
            }

            $this->auditLogger->record(
                user: $requestedBy,
                action: $autoApproved ? 'leave_record.approved' : 'leave_record.created',
                entityType: 'leave_records',
                entityId: $record->id,
                oldValue: null,
                newValue: [
                    'leave_type_id' => $record->leave_type_id,
                    'date_from' => $record->date_from->toDateString(),
                    'date_to' => $record->date_to->toDateString(),
                    'status' => $record->status,
                ],
                reason: $reason,
            );

            return $record;
        });

        if ($record->status === 'approved') {
            $this->triggerRecalculationForApprovedLeaveRecord($employee, $record);
        }

        return $record;
    }

    /**
     * @throws InvalidLeaveRecordStatusException
     */
    public function approve(LeaveRecord $record, User $approvedBy): LeaveRecord
    {
        if ($record->status !== 'pending') {
            throw new InvalidLeaveRecordStatusException($record->id, $record->status);
        }

        $record = DB::transaction(function () use ($record, $approvedBy) {
            $record->update([
                'status' => 'approved',
                'approved_by' => $approvedBy->id,
            ]);

            $this->generateNoveltyAndAbsence($record);

            $this->auditLogger->record(
                user: $approvedBy,
                action: 'leave_record.approved',
                entityType: 'leave_records',
                entityId: $record->id,
                oldValue: ['status' => 'pending'],
                newValue: ['status' => 'approved'],
            );

            return $record;
        });

        $this->triggerRecalculationForApprovedLeaveRecord($record->employee, $record);

        return $record;
    }

    /**
     * @throws InvalidLeaveRecordStatusException
     */
    public function reject(LeaveRecord $record, User $rejectedBy): LeaveRecord
    {
        if ($record->status !== 'pending') {
            throw new InvalidLeaveRecordStatusException($record->id, $record->status);
        }

        return DB::transaction(function () use ($record, $rejectedBy) {
            $record->update(['status' => 'rejected']);

            $this->auditLogger->record(
                user: $rejectedBy,
                action: 'leave_record.rejected',
                entityType: 'leave_records',
                entityId: $record->id,
                oldValue: ['status' => 'pending'],
                newValue: ['status' => 'rejected'],
            );

            return $record;
        });
    }

    /**
     * Resolves the novelty_type sharing `code` with this record's
     * leave_type (the correlation key seeded in lockstep by
     * EssentialNoveltyCatalogSeeder — there is no FK between the two
     * catalogs) and generates the novelty_records row mirroring this
     * record's status. Iff that novelty_type affects time calculation, also
     * generates one absence_records row per date in [date_from, date_to]
     * inclusive — the schema is one row per date, never a single row
     * spanning the range.
     *
     * @throws MissingNoveltyTypeForLeaveTypeException
     */
    private function generateNoveltyAndAbsence(LeaveRecord $record): void
    {
        // Resolved via LeaveType's own effectiveForCompany() scope, never
        // via the leaveType() relation's default query: BelongsToCompany's
        // global scope excludes company_id IS NULL rows whenever a company
        // is active (SQL's `column = value` never matches NULL — see
        // HasPlatformOrCompanyDefault's docblock), which would silently
        // turn every platform-default leave type — including all 4 seeded
        // by EssentialNoveltyCatalogSeeder — into a null relation the
        // instant this runs inside a real, company-scoped request.
        $leaveTypeCode = LeaveType::query()
            ->effectiveForCompany($record->company_id)
            ->whereKey($record->leave_type_id)
            ->value('code');

        // At most 2 rows can match (one platform default, one company
        // override sharing the same code) — resolved in PHP with the exact
        // same precedence rule as HasPlatformOrCompanyDefault::
        // effectiveCatalog(), so a company override never loses to the
        // platform default depending on undefined row order.
        $noveltyType = NoveltyType::query()
            ->effectiveForCompany($record->company_id)
            ->where('code', $leaveTypeCode)
            ->get()
            ->sortByDesc(fn (NoveltyType $candidate): bool => $candidate->company_id !== null)
            ->first();

        if ($noveltyType === null) {
            throw new MissingNoveltyTypeForLeaveTypeException($leaveTypeCode, $record->company_id);
        }

        NoveltyRecord::create([
            'company_id' => $record->company_id,
            'employee_id' => $record->employee_id,
            'novelty_type_id' => $noveltyType->id,
            'date_from' => $record->date_from->toDateString(),
            'date_to' => $record->date_to->toDateString(),
            'source_type' => 'leave_records',
            'source_id' => $record->id,
            'status' => $record->status,
        ]);

        if (! $noveltyType->affects_time_calc) {
            return;
        }

        // $record->date_from/date_to are Eloquent date-cast attributes,
        // which AppServiceProvider::configureDefaults() (Date::use(
        // CarbonImmutable::class)) makes CarbonImmutable — addDay() returns
        // a new instance instead of mutating in place, so the loop variable
        // must be reassigned every iteration or the condition never
        // advances (an infinite loop, not merely a stale value).
        for ($date = $record->date_from->copy(); $date->lte($record->date_to); $date = $date->addDay()) {
            AbsenceRecord::create([
                'company_id' => $record->company_id,
                'employee_id' => $record->employee_id,
                'date' => $date->toDateString(),
                'leave_record_id' => $record->id,
                'justified' => true,
                'source' => 'leave_approval',
            ]);
        }
    }

    /**
     * Shared by both places a leave record can become `approved` — the
     * auto-approve branch of create() and approve() itself. Deliberately
     * called AFTER the caller's own DB::transaction() has committed, never
     * nested inside it, for the exact same reason documented in
     * AttendanceAdjustmentService::triggerRecalculationForApprovedAdjustment():
     * the approval's own write (status, novelty/absence cascade, audit log)
     * must be durable regardless of Time Calculation's configuration state.
     *
     * Unlike TimeCalculationEngine::calculateForDate() (which throws 4
     * documented blocking exceptions — see AttendanceAdjustmentService for
     * that pattern), calculateForRange() already catches those same 4
     * exceptions internally, per date, and returns a status summary instead
     * of throwing (confirmed by reading TimeCalculationEngine::
     * calculateForRange()). There is therefore nothing left to catch at
     * this layer: a blocked date is logged from the returned summary
     * instead, giving the same "logged and skipped, never silently
     * ignored" treatment without a redundant try/catch that could never
     * trigger.
     *
     * Passes freshly-parsed Carbon::parse() values, not $record->date_from/
     * date_to directly: those are Eloquent date-cast attributes, which are
     * CarbonImmutable in this app (see the note in
     * generateNoveltyAndAbsence()). calculateForRange()'s own date-walking
     * loop (unmodified — out of scope this commit) assumes a mutable
     * Carbon parameter the same way ShiftGenerator::generate()'s identical
     * loop does; feeding it an immutable instance would silently hang
     * instead of throwing, since its own increment never advances either.
     */
    private function triggerRecalculationForApprovedLeaveRecord(Employee $employee, LeaveRecord $record): void
    {
        $results = $this->timeCalculationEngine->calculateForRange(
            $employee,
            Carbon::parse($record->date_from->toDateString()),
            Carbon::parse($record->date_to->toDateString()),
        );

        foreach ($results->where('status', 'blocked') as $result) {
            Log::warning('Recalculation skipped after leave record approval: '.$result['error'], [
                'leave_record_id' => $record->id,
                'employee_id' => $employee->id,
                'date' => $result['date'],
            ]);
        }
    }
}
