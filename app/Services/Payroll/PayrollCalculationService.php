<?php

namespace App\Services\Payroll;

use App\Exceptions\AmbiguousContractException;
use App\Exceptions\AmbiguousSalaryHistoryException;
use App\Models\Employee;
use App\Models\EmploymentContract;
use App\Models\PayrollPeriod;
use App\Models\SalaryHistory;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Liquidates devengado/deducido/neto per employee per payroll_periods, per
 * .ai/10-PAYROLL.md. Sin colaboradores por constructor (cálculo puro),
 * mirroring App\Services\TimeCalculation\TimeCalculationEngine: this class
 * has no persistence side effects of its own — calculateForEmployee()/
 * calculateForPeriod() (commit 10) will own the DB::transaction() /
 * PayrollEntry::updateOrCreate() wiring on top of the pure math built here.
 *
 * This commit only builds the contract sub-range resolution and base-salary
 * proration piece (plan section D/G) — no overtime, no deductions, nothing
 * persisted to payroll_entries/payroll_entry_lines yet.
 */
class PayrollCalculationService
{
    /**
     * Splits [$period->start_date, $period->end_date] into one sub-range per
     * employment_contracts row overlapping it, per .ai/10-PAYROLL.md
     * "Determinación de qué contrato/salario aplica a un periodo dado" and
     * the plan's section G mechanical walkthrough:
     *   1. Query every contract overlapping the period (same overlap
     *      predicate as EmploymentContract::activeForEmployeeAt(), but all
     *      matches — a period can legitimately span more than one contract,
     *      unlike a single-date lookup).
     *   2. Sort by start_date and clip each contract's own bounds to the
     *      period's bounds.
     *   3. Validate the sub-ranges tile the period exactly: no gap, no
     *      overlap, first sub-range starts at period.start_date, last one
     *      ends at period.end_date.
     *
     * Zero contracts, a gap, and an overlap are all treated identically —
     * AmbiguousContractException — never guessed around (project rule #16,
     * .ai/10-PAYROLL.md "Errores": "cero o más de un employment_contract
     * solapado sin cierre correcto ... nunca se adivina cuál contrato es el
     * correcto ni se promedia entre ellos"). A gap in coverage and an
     * overlap in coverage are both symptoms of the exact same underlying
     * data-integrity failure (a contract that was never closed correctly),
     * so they reuse EmploymentContract's own AmbiguousContractException
     * rather than inventing a second exception type for what is the same
     * class of ambiguity.
     *
     * @return Collection<int, array{contract: EmploymentContract, from: CarbonInterface, to: CarbonInterface}>
     *
     * @throws AmbiguousContractException
     */
    protected function resolveContractSubRanges(Employee $employee, PayrollPeriod $period): Collection
    {
        $contracts = EmploymentContract::query()
            ->where('employee_id', $employee->id)
            ->where('start_date', '<=', $period->end_date->toDateString())
            ->where(function ($query) use ($period) {
                $query->whereNull('end_date')->orWhere('end_date', '>=', $period->start_date->toDateString());
            })
            ->orderBy('start_date')
            ->get();

        $subRanges = $contracts->map(function (EmploymentContract $contract) use ($period): array {
            $from = $contract->start_date->greaterThan($period->start_date)
                ? $contract->start_date->copy()
                : $period->start_date->copy();

            $contractEnd = $contract->end_date ?? $period->end_date;
            $to = $contractEnd->lessThan($period->end_date)
                ? $contractEnd->copy()
                : $period->end_date->copy();

            return ['contract' => $contract, 'from' => $from, 'to' => $to];
        })->values();

        $this->assertSubRangesTilePeriodExactly($subRanges, $employee, $period);

        return $subRanges;
    }

    /**
     * Walks the sub-ranges in order and confirms they tile the period with
     * no gap and no overlap: the first sub-range must start exactly at
     * period.start_date, each next one must start exactly one day after the
     * previous one's end, and the last one must end exactly at
     * period.end_date. Any violation — including zero sub-ranges at all —
     * throws AmbiguousContractException; see resolveContractSubRanges()'s
     * docblock for why gap/overlap/absence are never distinguished.
     *
     * @param  Collection<int, array{contract: EmploymentContract, from: CarbonInterface, to: CarbonInterface}>  $subRanges
     *
     * @throws AmbiguousContractException
     */
    private function assertSubRangesTilePeriodExactly(Collection $subRanges, Employee $employee, PayrollPeriod $period): void
    {
        if ($subRanges->isEmpty()) {
            throw new AmbiguousContractException($employee->id, $period->start_date);
        }

        $expectedFrom = $period->start_date;

        foreach ($subRanges as $subRange) {
            if (! $subRange['from']->equalTo($expectedFrom)) {
                throw new AmbiguousContractException($employee->id, $period->start_date);
            }

            $expectedFrom = $subRange['to']->copy()->addDay();
        }

        if (! $subRanges->last()['to']->equalTo($period->end_date)) {
            throw new AmbiguousContractException($employee->id, $period->start_date);
        }
    }

    /**
     * Resolves the monthly salary in force for $contract at $from —
     * SalaryHistory::activeAt() if a revision covers that date, else the
     * contract's own base_salary — and converts it to a calendar-day rate.
     *
     * Two provisional product decisions, both documented (never silently
     * assumed, per project rule #16) and still open as PENDING DECISION in
     * .ai/10-PAYROLL.md pending real labor-law validation:
     *   - Proration is calendar-day based:
     *     tarifa_diaria = salario_mensual / díasDelMes($from). This is a
     *     confirmed-with-the-user placeholder criterion (plan "Decisiones de
     *     producto ya confirmadas", item 1), not a resolved legal fact.
     *   - A sub-range that crosses a month boundary uses the days-in-month
     *     of its own START date ($from), never the period's month and never
     *     an average across the crossed months. This is the plan's explicit
     *     documented resolution for that edge case (.ai/10-PAYROLL.md
     *     "Sub-rango de contrato que cruza un límite de mes": "la tarifa
     *     diaria usa el mes de la fecha de INICIO del sub-rango — lectura
     *     literal más simple, no inventa un criterio de prorrateo
     *     cruzado"). Carbon's daysInMonth on $from already reflects $from's
     *     own month, so no separate branch is needed for this case — it
     *     falls out of using $from consistently.
     *
     * @throws AmbiguousSalaryHistoryException
     */
    protected function resolveDailyRate(EmploymentContract $contract, CarbonInterface $from): float
    {
        $revision = SalaryHistory::activeAt($contract->id, $from);

        $monthlySalary = $revision !== null
            ? (float) $revision->base_salary
            : (float) $contract->base_salary;

        return $monthlySalary / $from->daysInMonth;
    }

    /**
     * Combines resolveContractSubRanges() and resolveDailyRate() into the
     * prorated BASE_SALARY line data for the whole period: one line per
     * sub-range, quantity in calendar days (inclusive of both endpoints),
     * rate as the calendar-day rate, amount = rate * days.
     *
     * Returns computed array data only — this commit does NOT persist
     * PayrollEntryLine rows; that wiring belongs to calculateForEmployee()
     * (commit 10), per the plan's phased scope for this commit.
     *
     * last_contract is the contract of the LAST sub-range, for
     * payroll_entries.contract_id — plan section G, step 8: "puntero de
     * resumen, no fuente de verdad — las líneas son la fuente de verdad del
     * prorrateo".
     *
     * @return array{lines: Collection<int, array{contract_id: string, quantity: float, rate: float, amount: float}>, last_contract: EmploymentContract}
     *
     * @throws AmbiguousContractException
     * @throws AmbiguousSalaryHistoryException
     */
    protected function proratedBaseSalaryLines(Employee $employee, PayrollPeriod $period): array
    {
        $subRanges = $this->resolveContractSubRanges($employee, $period);

        $lines = $subRanges->map(function (array $subRange): array {
            /** @var EmploymentContract $contract */
            $contract = $subRange['contract'];
            /** @var CarbonInterface $from */
            $from = $subRange['from'];
            /** @var CarbonInterface $to */
            $to = $subRange['to'];

            $days = $from->diffInDays($to) + 1;
            $rate = $this->resolveDailyRate($contract, $from);

            return [
                'contract_id' => $contract->id,
                'quantity' => (float) $days,
                'rate' => $rate,
                'amount' => $rate * $days,
            ];
        })->values();

        return [
            'lines' => $lines,
            'last_contract' => $subRanges->last()['contract'],
        ];
    }
}
