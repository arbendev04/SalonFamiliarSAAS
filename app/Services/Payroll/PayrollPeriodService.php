<?php

namespace App\Services\Payroll;

use App\Exceptions\InvalidPayrollPeriodStatusException;
use App\Exceptions\UnresolvedBlockedPayrollEntriesException;
use App\Models\PayrollDeductionPlan;
use App\Models\PayrollEntry;
use App\Models\PayrollEntryLine;
use App\Models\PayrollPeriod;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Pdf\PayrollReceiptService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * The period-level state machine wrapping PayrollCalculationService's
 * per-employee batch calculation, per .ai/10-PAYROLL.md "Ciclo completo":
 * open -> calculated -> approved(optional, ADR-034) -> closed ->
 * reopened(exceptional path, ADR-026).
 *
 * Same template as App\Services\Overtime\OvertimeRecordService: each public
 * method guards its required precondition status before opening a
 * transaction, then wraps its business write and AuditLogger::record() call
 * in one DB::transaction() (ADR-018). Every transition is audited
 * unconditionally (.ai/10-PAYROLL.md "Seguridad": "toda transición de estado
 * del periodo ... genera un registro en audit_logs de forma obligatoria").
 */
class PayrollPeriodService
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly PayrollCalculationService $calculationService,
        private readonly PayrollReceiptService $receiptService,
    ) {}

    /**
     * open|calculated|approved|reopened -> calculated.
     *
     * Per .ai/10-PAYROLL.md: "Mientras el periodo esté en OPEN o CALCULATED,
     * es libremente recalculable" — and per the plan for this commit, a
     * REOPENED period is also freely recalculable before it is closed again.
     * Only CLOSED blocks recalculation (a closed period is read-only at the
     * application level; correcting it goes exclusively through
     * payroll_adjustments/reopen(), never back through calculate()).
     *
     * The audit's newValue carries the batch outcome summary
     * (ok_count/blocked_count/blocked_employee_ids) rather than one audit row
     * per employee — this is the one required audit row for the whole
     * calculation, per the plan.
     *
     * @throws InvalidPayrollPeriodStatusException
     */
    public function calculate(PayrollPeriod $period, User $calculatedBy): PayrollPeriod
    {
        if ($period->status === 'closed') {
            throw new InvalidPayrollPeriodStatusException($period->id, $period->status, 'open|calculated|approved|reopened');
        }

        $oldStatus = $period->status;

        return DB::transaction(function () use ($period, $calculatedBy, $oldStatus): PayrollPeriod {
            $results = $this->calculationService->calculateForPeriod($period);

            $blocked = $results->where('status', 'blocked');

            $period->update(['status' => 'calculated']);

            $this->auditLogger->record(
                user: $calculatedBy,
                action: 'payroll_period.calculated',
                entityType: 'payroll_periods',
                entityId: $period->id,
                oldValue: ['status' => $oldStatus],
                newValue: [
                    'status' => 'calculated',
                    'ok_count' => $results->where('status', 'ok')->count(),
                    'blocked_count' => $blocked->count(),
                    'blocked_employee_ids' => $blocked->pluck('employee_id')->values()->all(),
                ],
            );

            return $period;
        });
    }

    /**
     * calculated -> approved.
     *
     * Optional step per ADR-034 (.ai/10-PAYROLL.md): payroll.close can run
     * directly from CALCULATED without ever passing through here. When it is
     * used, it is a real one-way transition — only reachable from CALCULATED,
     * never re-enterable from APPROVED itself.
     *
     * @throws InvalidPayrollPeriodStatusException
     */
    public function approve(PayrollPeriod $period, User $approvedBy): PayrollPeriod
    {
        if ($period->status !== 'calculated') {
            throw new InvalidPayrollPeriodStatusException($period->id, $period->status, 'calculated');
        }

        return DB::transaction(function () use ($period, $approvedBy): PayrollPeriod {
            $period->update(['status' => 'approved']);

            $this->auditLogger->record(
                user: $approvedBy,
                action: 'payroll_period.approved',
                entityType: 'payroll_periods',
                entityId: $period->id,
                oldValue: ['status' => 'calculated'],
                newValue: ['status' => 'approved'],
            );

            return $period;
        });
    }

    /**
     * calculated|approved|reopened -> closed.
     *
     * Per ADR-027, a single authorized role suffices (no maker-checker gate
     * enforced here — that authorization check belongs to the controller
     * layer's permission gate, a later commit).
     *
     * Blocked-entry check runs BEFORE the transaction opens: a period with
     * any payroll_entries.status='blocked' row can never close
     * (UnresolvedBlockedPayrollEntriesException) — those employees must be
     * fixed and recalculated first. Checking this ahead of the transaction
     * means a doomed close() never touches payroll_deduction_plans.remaining
     * at all.
     *
     * Decrementing `remaining` on the deduction plans applied this period
     * happens exactly once, here, and nowhere else — never during
     * PayrollCalculationService::calculateForEmployee()'s free recalculation.
     * A payroll_entry_line is traced back to its originating
     * PayrollDeductionPlan via `deduction_plan_id` (see
     * PayrollEntryLine::deductionPlan() and this commit's migration adding
     * that column — PayrollCalculationService::fixedDeductionLines() already
     * computed which plan a line came from, but had nowhere to persist it
     * until now).
     *
     * @throws InvalidPayrollPeriodStatusException
     * @throws UnresolvedBlockedPayrollEntriesException
     */
    public function close(PayrollPeriod $period, User $closedBy): PayrollPeriod
    {
        if (! in_array($period->status, ['calculated', 'approved', 'reopened'], true)) {
            throw new InvalidPayrollPeriodStatusException($period->id, $period->status, 'calculated|approved|reopened');
        }

        $blockedCount = PayrollEntry::query()
            ->where('payroll_period_id', $period->id)
            ->where('status', 'blocked')
            ->count();

        if ($blockedCount > 0) {
            throw new UnresolvedBlockedPayrollEntriesException($period->id, $blockedCount);
        }

        $oldStatus = $period->status;

        $period = DB::transaction(function () use ($period, $closedBy, $oldStatus): PayrollPeriod {
            $deductionLines = PayrollEntryLine::query()
                ->where('type', 'deduction')
                ->whereNotNull('deduction_plan_id')
                ->whereHas('payrollEntry', function ($query) use ($period) {
                    $query->where('payroll_period_id', $period->id);
                })
                ->get();

            foreach ($deductionLines as $line) {
                PayrollDeductionPlan::query()
                    ->whereKey($line->deduction_plan_id)
                    ->decrement('remaining', $line->amount);
            }

            $closedAt = now();

            $period->update([
                'status' => 'closed',
                'closed_by' => $closedBy->id,
                'closed_at' => $closedAt,
            ]);

            $this->auditLogger->record(
                user: $closedBy,
                action: 'payroll_period.closed',
                entityType: 'payroll_periods',
                entityId: $period->id,
                oldValue: ['status' => $oldStatus],
                newValue: [
                    'status' => 'closed',
                    'closed_by' => $closedBy->id,
                    'closed_at' => $closedAt->toISOString(),
                ],
            );

            return $period;
        });

        $this->generateReceiptsForClosedPeriod($period, $closedBy);

        return $period;
    }

    /**
     * Regenerates every payroll_entry's PDF receipt for a just-closed period
     * (.ai/14-PDF.md), per the plan's confirmed design: EVERY close() —
     * whether it is the period's first close() or a subsequent one after
     * reopen() -> free recalculation -> recordReopenCorrection() ->
     * close() again — unconditionally walks every current PayrollEntry and
     * calls PayrollReceiptService::generate() for it. That method itself
     * always resolves the next version via MAX(version)+1 per entry, so v1
     * on the first close() and v2 on a reopen+correct+reclose fall out of
     * this single, branch-free loop with no special-casing for "is this a
     * reclose" — see the plan's "Wiring en el cierre de periodo" section.
     *
     * Deliberately called AFTER the close() transaction above has already
     * committed, never nested inside it — same placement rationale as
     * AttendanceAdjustmentService::triggerRecalculationForApprovedAdjustment().
     * The close() transaction (status flip to 'closed', the deduction-plan
     * decrements, the mandatory AuditLogger::record() call) is the durable
     * source of truth per .ai/10-PAYROLL.md; a receipt is a derived artifact
     * materializing what that transaction already committed, not part of it.
     * Generating it inside the same transaction would let a PDF
     * rendering/storage failure roll back — or, if nested as a savepoint,
     * put at risk — a close() that has nothing to do with PDF rendering at
     * all.
     *
     * The catch here is `\Throwable`, deliberately broader than
     * triggerRecalculationForApprovedAdjustment()'s narrow list of four named
     * TimeCalculationEngine exceptions. That precedent can afford a narrow
     * list because TimeCalculationEngine documents its blocking failure
     * modes explicitly and exhaustively. Receipt generation's failure modes
     * are heterogeneous by construction: PayrollReceiptService::generate()
     * itself can throw MissingRequiredReceiptDataException (no lines) or
     * InvalidPayrollPeriodStatusException (defensive, should be unreachable
     * here since $period was just closed), but the PdfGenerator it calls can
     * also throw on a dompdf rendering failure, and Storage::put() can throw
     * on a storage-driver I/O failure — none of which are enumerable ahead of
     * time the way TimeCalculationEngine's are. None of them may ever be
     * allowed to undo an already-committed close(): one employee's
     * corrupted/missing data or a transient storage outage must never block
     * every other employee's receipt, nor retroactively fail a close() that
     * has already returned successfully to its caller. Trading a narrower
     * catch-list for "close() always succeeds once its own transaction
     * commits" is the deliberate, documented deviation from that precedent.
     */
    private function generateReceiptsForClosedPeriod(PayrollPeriod $period, User $closedBy): void
    {
        $entries = PayrollEntry::query()
            ->where('payroll_period_id', $period->id)
            ->get();

        foreach ($entries as $entry) {
            try {
                $this->receiptService->generate($entry, $closedBy);
            } catch (Throwable $e) {
                Log::warning('Payroll receipt generation skipped after period close: '.$e->getMessage(), [
                    'payroll_period_id' => $period->id,
                    'payroll_entry_id' => $entry->id,
                    'employee_id' => $entry->employee_id,
                    'exception' => $e::class,
                ]);
            }
        }
    }

    /**
     * closed -> reopened.
     *
     * Per ADR-026, reopening is the EXCEPTIONAL path, not the default one
     * (adjusting in the next open period is the default) — and per
     * .ai/10-PAYROLL.md "Seguridad", a reason is a REQUIRED field for REOPEN,
     * never optional. Enforced here at the service layer (not deferred to a
     * future FormRequest) because a blank reason must never be able to reach
     * an audit row: an empty/whitespace-only $reason throws
     * InvalidArgumentException rather than a domain status-conflict
     * exception, since this is a malformed-argument problem, not a
     * disagreement between the period's actual and expected status.
     *
     * closed_by/closed_at are deliberately left untouched here (not nulled
     * out) — they simply keep pointing at the PRIOR closure until a
     * subsequent close() overwrites them with a new closedBy/closed_at.
     * .ai/10-PAYROLL.md is explicit that the prior closure event is never
     * lost: "el evento de cierre anterior queda preservado en audit_logs, no
     * se pierde" — audit_logs is where that history lives, not a second copy
     * on payroll_periods itself.
     *
     * @throws InvalidPayrollPeriodStatusException
     */
    public function reopen(PayrollPeriod $period, User $reopenedBy, string $reason): PayrollPeriod
    {
        if ($period->status !== 'closed') {
            throw new InvalidPayrollPeriodStatusException($period->id, $period->status, 'closed');
        }

        if (trim($reason) === '') {
            throw new InvalidArgumentException('El motivo de reapertura es obligatorio.');
        }

        return DB::transaction(function () use ($period, $reopenedBy, $reason): PayrollPeriod {
            $period->update(['status' => 'reopened']);

            $this->auditLogger->record(
                user: $reopenedBy,
                action: 'payroll_period.reopened',
                entityType: 'payroll_periods',
                entityId: $period->id,
                oldValue: ['status' => 'closed'],
                newValue: ['status' => 'reopened', 'reason' => $reason],
                reason: $reason,
            );

            return $period;
        });
    }
}
