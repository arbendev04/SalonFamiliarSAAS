<?php

namespace App\Services\Overtime;

use App\Exceptions\InvalidOvertimeRecordStatusException;
use App\Models\OvertimeRecord;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * The 4-state lifecycle for overtime_records (Fase 8, section C of the
 * plan): request, authorize, reject, markPaid. `detected` rows are created
 * upstream by TimeCalculationEngine (Fase 8, section D) — this service only
 * builds the human-driven transitions from `detected` onward.
 *
 * Same template as App\Services\Leave\LeaveRecordService: each public method
 * guards its required precondition status before opening a transaction,
 * then wraps its business write and AuditLogger::record() call in one
 * DB::transaction() (ADR-018). Unlike LeaveRecordService/
 * AttendanceAdjustmentService, none of these 4 transitions has a
 * post-commit side effect — there is nothing downstream to recalculate from
 * an overtime status change (per the plan, section C).
 */
class OvertimeRecordService
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * detected -> requested.
     *
     * @throws InvalidOvertimeRecordStatusException
     */
    public function request(OvertimeRecord $record, User $requestedBy, int $requestedMinutes): OvertimeRecord
    {
        if ($record->status !== 'detected') {
            throw new InvalidOvertimeRecordStatusException($record->id, $record->status, 'detected');
        }

        return DB::transaction(function () use ($record, $requestedBy, $requestedMinutes) {
            $record->update([
                'requested_minutes' => $requestedMinutes,
                'status' => 'requested',
            ]);

            $this->auditLogger->record(
                user: $requestedBy,
                action: 'overtime_record.requested',
                entityType: 'overtime_records',
                entityId: $record->id,
                oldValue: ['status' => 'detected'],
                newValue: ['status' => 'requested', 'requested_minutes' => $requestedMinutes],
            );

            return $record;
        });
    }

    /**
     * requested -> authorized.
     *
     * $authorizedMinutes is deliberately unconstrained against
     * $record->requested_minutes: neither the plan nor any .ai/ doc for this
     * phase specifies whether authorizing more or less than what was
     * requested is allowed, and per project rule #16 (.ai/AGENTS.md) that
     * ambiguity is not resolved by inventing a rule here — it is accepted as
     * a plain int (a type-level constraint, not a business rule) pending a
     * real product decision.
     *
     * @throws InvalidOvertimeRecordStatusException
     */
    public function authorize(OvertimeRecord $record, User $authorizedBy, int $authorizedMinutes): OvertimeRecord
    {
        if ($record->status !== 'requested') {
            throw new InvalidOvertimeRecordStatusException($record->id, $record->status, 'requested');
        }

        return DB::transaction(function () use ($record, $authorizedBy, $authorizedMinutes) {
            $record->update([
                'authorized_minutes' => $authorizedMinutes,
                'status' => 'authorized',
            ]);

            $this->auditLogger->record(
                user: $authorizedBy,
                action: 'overtime_record.authorized',
                entityType: 'overtime_records',
                entityId: $record->id,
                oldValue: ['status' => 'requested'],
                newValue: ['status' => 'authorized', 'authorized_minutes' => $authorizedMinutes],
            );

            return $record;
        });
    }

    /**
     * requested -> rejected.
     *
     * @throws InvalidOvertimeRecordStatusException
     */
    public function reject(OvertimeRecord $record, User $rejectedBy): OvertimeRecord
    {
        if ($record->status !== 'requested') {
            throw new InvalidOvertimeRecordStatusException($record->id, $record->status, 'requested');
        }

        return DB::transaction(function () use ($record, $rejectedBy) {
            $record->update(['status' => 'rejected']);

            $this->auditLogger->record(
                user: $rejectedBy,
                action: 'overtime_record.rejected',
                entityType: 'overtime_records',
                entityId: $record->id,
                oldValue: ['status' => 'requested'],
                newValue: ['status' => 'rejected'],
            );

            return $record;
        });
    }

    /**
     * authorized -> paid.
     *
     * @throws InvalidOvertimeRecordStatusException
     */
    public function markPaid(OvertimeRecord $record, User $markedBy): OvertimeRecord
    {
        if ($record->status !== 'authorized') {
            throw new InvalidOvertimeRecordStatusException($record->id, $record->status, 'authorized');
        }

        return DB::transaction(function () use ($record, $markedBy) {
            $record->update(['status' => 'paid']);

            $this->auditLogger->record(
                user: $markedBy,
                action: 'overtime_record.paid',
                entityType: 'overtime_records',
                entityId: $record->id,
                oldValue: ['status' => 'authorized'],
                newValue: ['status' => 'paid'],
            );

            return $record;
        });
    }
}
