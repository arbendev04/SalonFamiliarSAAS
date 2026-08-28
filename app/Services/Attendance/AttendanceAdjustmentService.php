<?php

namespace App\Services\Attendance;

use App\Exceptions\InvalidAttendanceAdjustmentStatusException;
use App\Models\AttendanceAdjustment;
use App\Models\AttendanceEvent;
use App\Models\Employee;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Implements Flujo 2 of .ai/07-ATTENDANCE.md: the formal correction
 * mechanism over attendance_events. The original event is never edited or
 * deleted (ADR-003) — every correction, including "the event was missing
 * entirely", is expressed as a new attendance_adjustments row.
 *
 * Per ADR-018, AuditLogger::record() never opens its own transaction: every
 * public method here wraps its business write and the audit call in one
 * DB::transaction, replicating the exact pattern in
 * ShiftAssignmentController::update().
 */
class AttendanceAdjustmentService
{
    public function __construct(
        private readonly AttendanceEventRecorder $recorder,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * @param  array<string, mixed>|null  $originalValue
     * @param  array<string, mixed>  $correctedValue
     */
    public function create(
        Employee $employee,
        User $requestedBy,
        string $type,
        ?AttendanceEvent $originalEvent,
        ?array $originalValue,
        array $correctedValue,
        string $reason,
    ): AttendanceAdjustment {
        return DB::transaction(function () use ($employee, $requestedBy, $type, $originalEvent, $originalValue, $correctedValue, $reason) {
            // ADR-032: auto-approval is derived directly from the
            // requester's own RBAC grant, never from a role name hardcoded
            // here, so this can never drift out of sync with RoleSeeder.
            $autoApproved = $requestedBy->hasPermission('attendance.approve_adjustment');
            $status = $autoApproved ? 'approved' : 'pending';

            $adjustment = AttendanceAdjustment::create([
                'company_id' => $employee->company_id,
                'original_event_id' => $originalEvent?->id,
                'employee_id' => $employee->id,
                'type' => $type,
                'original_value' => $originalValue,
                'corrected_value' => $correctedValue,
                'reason' => $reason,
                'requested_by' => $requestedBy->id,
                'approved_by' => $autoApproved ? $requestedBy->id : null,
                'status' => $status,
            ]);

            if ($autoApproved && $type === 'add') {
                $this->insertEventForAddAdjustment($employee, $adjustment);
            }

            $this->auditLogger->record(
                user: $requestedBy,
                action: $autoApproved ? 'attendance_adjustment.approved' : 'attendance_adjustment.created',
                entityType: 'attendance_adjustments',
                entityId: $adjustment->id,
                oldValue: null,
                newValue: [
                    'type' => $adjustment->type,
                    'original_value' => $adjustment->original_value,
                    'corrected_value' => $adjustment->corrected_value,
                    'status' => $adjustment->status,
                ],
                reason: $reason,
            );

            return $adjustment;
        });
    }

    /**
     * @throws InvalidAttendanceAdjustmentStatusException
     */
    public function approve(AttendanceAdjustment $adjustment, User $approvedBy, ?string $note = null): AttendanceAdjustment
    {
        if ($adjustment->status !== 'pending') {
            throw new InvalidAttendanceAdjustmentStatusException($adjustment->id, $adjustment->status);
        }

        return DB::transaction(function () use ($adjustment, $approvedBy, $note) {
            $adjustment->update([
                'status' => 'approved',
                'approved_by' => $approvedBy->id,
            ]);

            if ($adjustment->type === 'add') {
                $this->insertEventForAddAdjustment($adjustment->employee, $adjustment);
            }

            $this->auditLogger->record(
                user: $approvedBy,
                action: 'attendance_adjustment.approved',
                entityType: 'attendance_adjustments',
                entityId: $adjustment->id,
                oldValue: ['status' => 'pending'],
                newValue: ['status' => 'approved'],
                reason: $note,
            );

            return $adjustment;
        });
    }

    /**
     * @throws InvalidAttendanceAdjustmentStatusException
     */
    public function reject(AttendanceAdjustment $adjustment, User $rejectedBy, ?string $note = null): AttendanceAdjustment
    {
        if ($adjustment->status !== 'pending') {
            throw new InvalidAttendanceAdjustmentStatusException($adjustment->id, $adjustment->status);
        }

        return DB::transaction(function () use ($adjustment, $rejectedBy, $note) {
            $adjustment->update(['status' => 'rejected']);

            $this->auditLogger->record(
                user: $rejectedBy,
                action: 'attendance_adjustment.rejected',
                entityType: 'attendance_adjustments',
                entityId: $adjustment->id,
                oldValue: ['status' => 'pending'],
                newValue: ['status' => 'rejected'],
                reason: $note,
            );

            return $adjustment;
        });
    }

    /**
     * Shared by the auto-approve branch of create() and the explicit
     * approve() flow, so a type=add adjustment always inserts its
     * AttendanceEvent through the exact same path regardless of who ends up
     * approving it. corrected_value must carry event_type/event_datetime
     * for this adjustment type — see StoreAttendanceAdjustmentRequest.
     *
     * type=modify/invalidate never reach here: they leave the original
     * event untouched in the table. Fase 7 (Time Calculation) is what will
     * read the adjustment alongside the event to determine the effective
     * value at calculation time — not built in this commit.
     */
    private function insertEventForAddAdjustment(Employee $employee, AttendanceAdjustment $adjustment): void
    {
        $this->recorder->record(
            employee: $employee,
            eventType: $adjustment->corrected_value['event_type'],
            eventDatetime: Carbon::parse($adjustment->corrected_value['event_datetime']),
            source: 'manual',
            extraMetadata: ['created_from_adjustment_id' => $adjustment->id],
        );
    }
}
