<?php

namespace App\Services\Attendance;

use App\Exceptions\AmbiguousLaborRuleVersionException;
use App\Exceptions\InvalidAttendanceAdjustmentStatusException;
use App\Exceptions\MissingCriticalAttendanceEventException;
use App\Exceptions\MissingLaborRuleParameterException;
use App\Exceptions\NoActiveLaborRuleVersionException;
use App\Models\AttendanceAdjustment;
use App\Models\AttendanceEvent;
use App\Models\Employee;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\TimeCalculation\TimeCalculationEngine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
 *
 * Per .ai/07-ATTENDANCE.md (Flujo 2, step 6) and .ai/09-TIME-CALCULATION.md,
 * an adjustment that becomes `approved` — whether auto-approved in create()
 * or approved later via approve() — must also trigger
 * TimeCalculationEngine::calculateForDate() for the date it affects. See
 * triggerRecalculationForApprovedAdjustment() for why that call runs AFTER
 * the adjustment's own DB::transaction() has committed, and why its
 * blocking exceptions are caught rather than allowed to fail the
 * create()/approve() call itself.
 */
class AttendanceAdjustmentService
{
    public function __construct(
        private readonly AttendanceEventRecorder $recorder,
        private readonly AuditLogger $auditLogger,
        private readonly TimeCalculationEngine $timeCalculationEngine,
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
        $adjustment = DB::transaction(function () use ($employee, $requestedBy, $type, $originalEvent, $originalValue, $correctedValue, $reason) {
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

        if ($adjustment->status === 'approved') {
            $this->triggerRecalculationForApprovedAdjustment($employee, $adjustment);
        }

        return $adjustment;
    }

    /**
     * @throws InvalidAttendanceAdjustmentStatusException
     */
    public function approve(AttendanceAdjustment $adjustment, User $approvedBy, ?string $note = null): AttendanceAdjustment
    {
        if ($adjustment->status !== 'pending') {
            throw new InvalidAttendanceAdjustmentStatusException($adjustment->id, $adjustment->status);
        }

        $adjustment = DB::transaction(function () use ($adjustment, $approvedBy, $note) {
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

        $this->triggerRecalculationForApprovedAdjustment($adjustment->employee, $adjustment);

        return $adjustment;
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

    /**
     * Shared by both places an adjustment can become `approved` — the
     * auto-approve branch of create() and approve() itself — so recalculation
     * is always triggered through the exact same path per
     * .ai/07-ATTENDANCE.md (Flujo 2, step 6).
     *
     * The affected date is the original event's date for `modify`/
     * `invalidate` (the correction is expressed against an existing
     * marking), or corrected_value['event_datetime'] for `add` (there is no
     * original event to read a date from).
     *
     * Deliberately called AFTER the caller's own DB::transaction() has
     * committed, never nested inside it. TimeCalculationEngine::
     * calculateForDate() opens its own internal DB::transaction() for the
     * AttendanceRecord + TimeCalculationRun writes; Laravel would run that
     * as a savepoint if nested here, and a caught exception from it would,
     * in principle, only roll back to that savepoint rather than the outer
     * transaction. But relying on that is unnecessary complexity for no
     * benefit: by calling this post-commit instead, the adjustment's own
     * write (company_id, status, audit log — the part that IS in the
     * acceptance criteria and must never fail silently) is already durable
     * on disk before recalculation is even attempted, so nothing this
     * method does or throws can ever affect it, regardless of driver or
     * transaction-nesting behavior.
     *
     * Approving/creating an adjustment must succeed independently of Time
     * Calculation's configuration state: a company may not have set up a
     * labor_rule_version yet, or the recalculation may hit a blocking data
     * issue elsewhere on that date (missing critical event, ambiguous rule
     * version, etc.) that has nothing to do with whether THIS specific
     * correction was recorded correctly. Those are the exact blocking
     * exceptions TimeCalculationEngine documents itself as throwing. This
     * is the closest existing analogue to the "señal de ajuste de nómina
     * pendiente" language in the docs, but that signal has nowhere to live
     * yet — there is no payroll module (Fase 9) to receive it. Until then
     * this is logged-and-skipped, not silently ignored: a future phase can
     * promote this into a real signal/notification once one exists.
     */
    private function triggerRecalculationForApprovedAdjustment(Employee $employee, AttendanceAdjustment $adjustment): void
    {
        $date = $adjustment->type === 'add'
            ? Carbon::parse($adjustment->corrected_value['event_datetime'])
            : Carbon::parse($adjustment->originalEvent->event_datetime);

        try {
            $this->timeCalculationEngine->calculateForDate($employee, $date->startOfDay());
        } catch (NoActiveLaborRuleVersionException|AmbiguousLaborRuleVersionException|MissingLaborRuleParameterException|MissingCriticalAttendanceEventException $e) {
            Log::warning('Recalculation skipped after attendance adjustment approval: '.$e->getMessage(), [
                'adjustment_id' => $adjustment->id,
                'employee_id' => $employee->id,
                'date' => $date->toDateString(),
                'exception' => $e::class,
            ]);
        }
    }
}
