<?php

namespace App\Services\Payroll;

use App\Exceptions\InvalidPayrollPeriodStatusException;
use App\Exceptions\NoOpenNextPayrollPeriodException;
use App\Models\PayrollAdjustment;
use App\Models\PayrollEntry;
use App\Models\PayrollPeriod;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Implements the two ADR-026 correction mechanisms over a CLOSED
 * payroll_entries row, per the plan's section F and .ai/10-PAYROLL.md
 * Flujo (c) step 6: a closed entry is never edited directly — every
 * correction is expressed as a payroll_adjustments row.
 *
 * Only ever CREATEs a PayrollAdjustment, never updates one — the model's
 * own two-layer immutability guard (see PayrollAdjustment::booted() /
 * PayrollAdjustmentImmutableBuilder) would reject an update() attempt
 * anyway, but this service does not even try.
 */
class PayrollAdjustmentService
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * The DEFAULT correction path (ADR-026): injects a compensating line into
     * the employee's next open/calculated payroll_periods row rather than
     * touching the closed entry. Per the plan, this is "el camino por
     * defecto, el que se prueba a fondo" — the one with full test coverage.
     *
     * Resolution order, all pre-transaction (fail-fast, mirrors
     * PayrollCalculationService::calculateForEmployee()'s discipline of
     * computing/validating everything before opening a transaction):
     *   1. $closedEntry's OWN period must actually be 'closed'. Adjusting a
     *      non-closed entry through this mechanism makes no sense — a
     *      non-closed entry is simply recalculated normally via
     *      PayrollCalculationService, never "adjusted". Reuses
     *      InvalidPayrollPeriodStatusException (already the exact
     *      "wrong period status for this operation" exception this codebase
     *      uses) rather than inventing a new one for the same precondition
     *      shape.
     *   2. The next company-wide payroll_periods row in status open/
     *      calculated whose start_date is after $closedEntry's period's
     *      end_date. payroll_periods is company-scoped, not employee-scoped,
     *      so "the employee's next period" concretely means "the next
     *      company period in which the employee has a settlement to carry
     *      the correction". Per the plan ("nunca se crea un periodo
     *      automáticamente — eso sería inventar una política"), no period is
     *      ever created here — NoOpenNextPayrollPeriodException if none
     *      exists.
     *   3. That target period must already have a PayrollEntry for this
     *      employee. DECISION (not fully dictated by the plan, made here):
     *      when a next open/calculated period exists but
     *      PayrollCalculationService::calculateForEmployee() has never run
     *      for this employee in it, this method treats that exactly like "no
     *      open next period" and throws the same
     *      NoOpenNextPayrollPeriodException, rather than creating an orphan
     *      PayrollEntry to hang the line off of. Fabricating that entry here
     *      would produce a PayrollEntry whose totals reflect only this one
     *      injected line instead of a real settlement — a different, more
     *      silent kind of data corruption than the one ADR-026 exists to
     *      prevent. Requiring calculateForPeriod()/calculateForEmployee() to
     *      run first keeps PayrollEntry.gross_total/deductions_total/
     *      net_total meaning the same thing everywhere: "the full settlement
     *      for this employee this period, plus any adjustments layered on
     *      top", never "whatever happened to be written to this row".
     *
     * Persists, in ONE DB::transaction():
     *   - a new payroll_entry_lines row on the TARGET entry (never the
     *     closed one);
     *   - the TARGET entry's gross_total/deductions_total/net_total updated
     *     to include it;
     *   - the payroll_adjustments row itself. Its payroll_entry_id points at
     *     $closedEntry — the entry this adjustment CORRECTS — not the target
     *     entry the money actually landed on; applied_in_period_id is what
     *     names the target period. Getting this backwards would make the
     *     column mean "which entry received the correction" instead of
     *     "which entry was being corrected", silently breaking any future
     *     "show me every adjustment against this closed entry" query.
     *   - one AuditLogger::record() call.
     *
     * $closedEntry and its own payroll_entry_lines are never written to by
     * this method — that is the entire point of the mechanism (ADR-026: "un
     * ajuste posterior al cierre no sobrescribe la entrada original").
     *
     * @throws InvalidPayrollPeriodStatusException
     * @throws NoOpenNextPayrollPeriodException
     */
    public function adjustInNextPeriod(
        PayrollEntry $closedEntry,
        User $createdBy,
        string $conceptId,
        float $amount,
        string $type,
        string $reason,
    ): PayrollAdjustment {
        if ($type !== 'earning' && $type !== 'deduction') {
            throw new InvalidArgumentException("El tipo de línea de ajuste debe ser 'earning' o 'deduction', se recibió '{$type}'.");
        }

        $closedPeriod = $closedEntry->payrollPeriod;

        if ($closedPeriod->status !== 'closed') {
            throw new InvalidPayrollPeriodStatusException($closedPeriod->id, $closedPeriod->status, 'closed');
        }

        $targetPeriod = PayrollPeriod::query()
            ->where('company_id', $closedEntry->company_id)
            ->whereIn('status', ['open', 'calculated'])
            ->where('start_date', '>', $closedPeriod->end_date)
            ->orderBy('start_date')
            ->first();

        if ($targetPeriod === null) {
            throw new NoOpenNextPayrollPeriodException($closedEntry->employee_id, $closedEntry->payroll_period_id);
        }

        $targetEntry = PayrollEntry::query()
            ->where('payroll_period_id', $targetPeriod->id)
            ->where('employee_id', $closedEntry->employee_id)
            ->first();

        // No PayrollEntry exists yet for this employee in the target period
        // (never calculated) — see the docblock's decision #3: there is
        // nothing valid to attach the correction to, so this is treated the
        // same as "no open next period exists".
        if ($targetEntry === null) {
            throw new NoOpenNextPayrollPeriodException($closedEntry->employee_id, $closedEntry->payroll_period_id);
        }

        return DB::transaction(function () use (
            $closedEntry,
            $createdBy,
            $conceptId,
            $amount,
            $type,
            $reason,
            $targetPeriod,
            $targetEntry,
        ): PayrollAdjustment {
            $targetEntry->lines()->create([
                'company_id' => $targetEntry->company_id,
                'concept_id' => $conceptId,
                'contract_id' => null,
                'type' => $type,
                'quantity' => null,
                'rate' => null,
                'amount' => $amount,
            ]);

            $grossTotal = (float) $targetEntry->gross_total;
            $deductionsTotal = (float) $targetEntry->deductions_total;

            if ($type === 'earning') {
                $grossTotal += $amount;
            } else {
                $deductionsTotal += $amount;
            }

            $targetEntry->update([
                'gross_total' => $grossTotal,
                'deductions_total' => $deductionsTotal,
                'net_total' => $grossTotal - $deductionsTotal,
            ]);

            $adjustment = PayrollAdjustment::create([
                'company_id' => $closedEntry->company_id,
                // The ORIGINAL closed entry being corrected — see this
                // method's docblock for why this is never $targetEntry->id.
                'payroll_entry_id' => $closedEntry->id,
                'mechanism' => 'next_period',
                // Nothing pre-existing on the closed entry is being
                // overwritten — the correction is purely additive.
                'original_value' => null,
                'corrected_value' => [
                    'concept_id' => $conceptId,
                    'amount' => $amount,
                    'type' => $type,
                ],
                'reason' => $reason,
                'created_by' => $createdBy->id,
                'applied_in_period_id' => $targetPeriod->id,
            ]);

            $this->auditLogger->record(
                user: $createdBy,
                action: 'payroll_adjustment.created',
                entityType: 'payroll_adjustments',
                entityId: $adjustment->id,
                oldValue: null,
                newValue: [
                    'mechanism' => 'next_period',
                    'payroll_entry_id' => $closedEntry->id,
                    'applied_in_period_id' => $targetPeriod->id,
                    'concept_id' => $conceptId,
                    'amount' => $amount,
                    'type' => $type,
                ],
                reason: $reason,
            );

            return $adjustment;
        });
    }

    /**
     * The EXCEPTIONAL correction path (ADR-026): records the audit trail for
     * a correction applied while $entry's period is temporarily 'reopened'.
     * Unlike adjustInNextPeriod(), this method never mutates any
     * payroll_entries/payroll_entry_lines row itself — that mutation happens
     * separately, via PayrollCalculationService::calculateForEmployee()
     * running freely while the period is 'reopened' (already a valid source
     * status per commit 11's calculate()). This method only writes the
     * append-only trail of what changed and why.
     *
     * applied_in_period_id is null here (unlike adjustInNextPeriod()) because
     * the correction lands in the SAME period being corrected in place, not
     * a different target period — there is nothing else to name.
     */
    public function recordReopenCorrection(
        PayrollEntry $entry,
        User $createdBy,
        ?array $originalValue,
        array $correctedValue,
        string $reason,
    ): PayrollAdjustment {
        return DB::transaction(function () use ($entry, $createdBy, $originalValue, $correctedValue, $reason): PayrollAdjustment {
            $adjustment = PayrollAdjustment::create([
                'company_id' => $entry->company_id,
                'payroll_entry_id' => $entry->id,
                'mechanism' => 'reopen',
                'original_value' => $originalValue,
                'corrected_value' => $correctedValue,
                'reason' => $reason,
                'created_by' => $createdBy->id,
                'applied_in_period_id' => null,
            ]);

            $this->auditLogger->record(
                user: $createdBy,
                action: 'payroll_adjustment.created',
                entityType: 'payroll_adjustments',
                entityId: $adjustment->id,
                oldValue: $originalValue,
                newValue: $correctedValue,
                reason: $reason,
            );

            return $adjustment;
        });
    }
}
