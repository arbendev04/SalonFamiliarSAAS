<?php

namespace App\Services\Payroll;

use App\Exceptions\AmbiguousContractException;
use App\Exceptions\AmbiguousLaborRuleVersionException;
use App\Exceptions\AmbiguousSalaryHistoryException;
use App\Exceptions\MissingLaborRuleParameterException;
use App\Exceptions\NoActiveLaborRuleVersionException;
use App\Exceptions\NoAttendanceOrNoveltyDataException;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\EmploymentContract;
use App\Models\LaborRule;
use App\Models\LaborRuleVersion;
use App\Models\NoveltyRecord;
use App\Models\OvertimeRecord;
use App\Models\PayrollPeriod;
use App\Models\SalaryHistory;
use App\Services\TimeCalculation\TimeCalculationEngine;
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
 * This commit adds the authorized-overtime-to-money translation (plan
 * section D, "Horas extra") on top of commits 6-7's contract sub-range
 * resolution and base-salary proration — no deductions yet, nothing
 * persisted to payroll_entries/payroll_entry_lines yet.
 */
class PayrollCalculationService
{
    /**
     * Guards against liquidating an employee for whom attendance_records and
     * novelty_records are both completely silent over the period — the
     * "empleado sin ningún attendance_record ni novelty_record que cubra el
     * periodo" case, per the plan's confirmed product decision #2
     * (composed-knitting-dusk.md, section D) and project rule #16: this
     * specific employee's calculation is blocked with an explicit error
     * rather than assuming or zero-filling missing time data. This mirrors
     * the "contrato ambiguo" pattern in resolveContractSubRanges() — a data
     * void for one employee never silently degrades into a guessed number,
     * it blocks that employee while leaving the rest of the batch unaffected.
     *
     * Coverage is satisfied by EITHER of:
     *   - at least one AttendanceRecord with `date` inside
     *     [period.start_date, period.end_date], OR
     *   - at least one NoveltyRecord with status='approved' whose
     *     [date_from, date_to] range overlaps the period — the same
     *     range-overlap predicate shape as resolveContractSubRanges()'s
     *     contract query (start <= period.end AND end >= period.start),
     *     and the same "only approved counts" rule already established by
     *     NoveltyRecordLookup.
     *
     * This check is intentionally coarse: it confirms SOME record exists
     * somewhere in the period, not that EVERY single day of the period is
     * covered. A genuinely per-day completeness check is not required by any
     * confirmed acceptance criterion here and would invent an unspecified
     * completeness threshold — out of scope for this guard.
     *
     * @throws NoAttendanceOrNoveltyDataException
     */
    protected function assertHasAttendanceOrNoveltyCoverage(Employee $employee, PayrollPeriod $period): void
    {
        $hasAttendanceRecord = AttendanceRecord::query()
            ->where('employee_id', $employee->id)
            ->whereBetween('date', [$period->start_date->toDateString(), $period->end_date->toDateString()])
            ->exists();

        if ($hasAttendanceRecord) {
            return;
        }

        $hasApprovedNoveltyRecord = NoveltyRecord::query()
            ->where('employee_id', $employee->id)
            ->where('status', 'approved')
            ->where('date_from', '<=', $period->end_date->toDateString())
            ->where('date_to', '>=', $period->start_date->toDateString())
            ->exists();

        if ($hasApprovedNoveltyRecord) {
            return;
        }

        throw new NoAttendanceOrNoveltyDataException($employee->id, $period->id);
    }

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
     * Resolves the monthly salary in force for $contract at $date —
     * SalaryHistory::activeAt() if a revision covers that date, else the
     * contract's own base_salary. Extracted out of resolveDailyRate() (and
     * reused by authorizedOvertimeLines()) so this SalaryHistory::
     * activeAt() + contract-fallback lookup — the actual "which salary
     * applies" decision — is not duplicated between the two money-derivation
     * paths that both need it: resolveDailyRate() further divides it into a
     * calendar-day rate, authorizedOvertimeLines() divides it into an hourly
     * rate instead.
     *
     * @throws AmbiguousSalaryHistoryException
     */
    protected function resolveMonthlySalary(EmploymentContract $contract, CarbonInterface $date): float
    {
        $revision = SalaryHistory::activeAt($contract->id, $date);

        return $revision !== null
            ? (float) $revision->base_salary
            : (float) $contract->base_salary;
    }

    /**
     * Converts resolveMonthlySalary() into a calendar-day rate.
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
        return $this->resolveMonthlySalary($contract, $from) / $from->daysInMonth;
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

    /**
     * Translates AUTHORIZED overtime into OVERTIME payroll_entry_lines data,
     * per .ai/10-PAYROLL.md plan section D ("Horas extra"). Only
     * `overtime_records.status = 'authorized'` counts — `detected`,
     * `requested`, and `rejected` are all excluded, since only a human
     * approval turns a detected overtime candidate into money (the same
     * status this codebase already treats as the money-relevant one in
     * App\Services\Overtime\OvertimeRecordService's lifecycle).
     * `overtime_records` has no date column of its own; membership in the
     * period is decided by its related `shifts.date` falling inside
     * [period.start_date, period.end_date].
     *
     * Zero authorized overtime records for the period is a completely
     * normal outcome — an empty Collection, never an error — unlike
     * assertHasAttendanceOrNoveltyCoverage()'s guard, which blocks the whole
     * employee when attendance/novelty coverage is silent. Having no
     * overtime approved for a period says nothing about whether the
     * employee's base salary/attendance data is present.
     *
     * For each authorized OvertimeRecord, resolves the active
     * labor_rule_version for the employee's company on the record's shift
     * date, using the exact same rule_type=STANDARD_WORKWEEK lookup and
     * blocking behavior (NoActiveLaborRuleVersionException /
     * AmbiguousLaborRuleVersionException) as
     * TimeCalculationEngine::resolveActiveRuleVersion() — matched rather
     * than reinvented, per the Fase 7 precedent this method is required to
     * follow. Resolved at most once per unique shift date (multiple
     * overtime records commonly share a date) as a small optimization, not
     * a correctness requirement.
     *
     * Requires `monthly_hours_divisor` and `overtime_surcharge_pct` in that
     * version's `parameters`, blocking via MissingLaborRuleParameterException
     * when either is absent — the same established contract
     * TimeCalculationEngine::resolveParameters() uses for
     * tolerance_minutes/rounding_minutes.
     *
     * Formula (an ENGINEERING interpretation of what "surcharge percentage"
     * conventionally means in payroll systems — only the FORMULA SHAPE is
     * asserted here, never a legally-validated Colombian percentage; the
     * value itself always comes from labor_rule_versions.parameters, never
     * hardcoded, per project rule #15):
     *   hourly_rate    = monthly_salary / monthly_hours_divisor
     *   overtime_rate  = hourly_rate * (1 + overtime_surcharge_pct)
     *   amount         = overtime_rate * (authorized_minutes / 60)
     * i.e. overtime_surcharge_pct = 0.25 pays overtime hours at 125% of the
     * normal hourly rate ("recargo del 25%" ADDITIVE on top of the base
     * rate, the conventional Colombian payroll reading), not as an absolute
     * 25% rate.
     *
     * Deviates from the plan sketch's exact visibility — declared here as
     * `protected`, not `private` — to stay consistent with every other
     * testable computational helper already in this class
     * (resolveContractSubRanges()/resolveDailyRate()/
     * proratedBaseSalaryLines()/assertHasAttendanceOrNoveltyCoverage() are
     * all `protected` for exactly this reason): a `private` method here
     * would not be reachable from the anonymous-subclass test wrapper this
     * file's tests use, since PHP does not allow a subclass to call a
     * parent's private method even via inherited scope.
     *
     * @return Collection<int, array{concept_code: string, quantity: float, rate: float, amount: float}>
     *
     * @throws NoActiveLaborRuleVersionException
     * @throws AmbiguousLaborRuleVersionException
     * @throws MissingLaborRuleParameterException
     * @throws AmbiguousSalaryHistoryException
     * @throws AmbiguousContractException
     */
    protected function authorizedOvertimeLines(Employee $employee, PayrollPeriod $period): Collection
    {
        $overtimeRecords = OvertimeRecord::query()
            ->where('employee_id', $employee->id)
            ->where('status', 'authorized')
            ->whereHas('shift', function ($query) use ($period) {
                $query->whereBetween('date', [$period->start_date->toDateString(), $period->end_date->toDateString()]);
            })
            ->with('shift')
            ->get();

        if ($overtimeRecords->isEmpty()) {
            return collect();
        }

        /** @var array<string, LaborRuleVersion> $ruleVersionsByDate */
        $ruleVersionsByDate = [];

        return $overtimeRecords->map(function (OvertimeRecord $overtimeRecord) use ($employee, &$ruleVersionsByDate): array {
            $shiftDate = $overtimeRecord->shift->date;
            $dateKey = $shiftDate->toDateString();

            if (! array_key_exists($dateKey, $ruleVersionsByDate)) {
                $ruleVersionsByDate[$dateKey] = $this->resolveActiveLaborRuleVersion($employee, $shiftDate);
            }

            [$monthlyHoursDivisor, $overtimeSurchargePct] = $this->requireOvertimeParameters($ruleVersionsByDate[$dateKey]);

            $contract = $this->resolveContractForOvertime($employee, $shiftDate);
            $monthlySalary = $this->resolveMonthlySalary($contract, $shiftDate);

            $hourlyRate = $monthlySalary / $monthlyHoursDivisor;
            $overtimeRate = $hourlyRate * (1 + $overtimeSurchargePct);
            $overtimeHours = $overtimeRecord->authorized_minutes / 60;

            return [
                'concept_code' => 'OVERTIME',
                'quantity' => $overtimeHours,
                'rate' => $overtimeRate,
                'amount' => $overtimeRate * $overtimeHours,
            ];
        })->values();
    }

    /**
     * Same rule_type=STANDARD_WORKWEEK lookup and blocking behavior as
     * TimeCalculationEngine::resolveActiveRuleVersion() — reuses that
     * class's public RULE_TYPE_STANDARD_WORKWEEK constant rather than
     * duplicating the literal string, so both engines stay pointed at the
     * exact same labor_rules row shape if it's ever renamed.
     *
     * @throws AmbiguousLaborRuleVersionException
     * @throws NoActiveLaborRuleVersionException
     */
    private function resolveActiveLaborRuleVersion(Employee $employee, CarbonInterface $date): LaborRuleVersion
    {
        $laborRule = LaborRule::query()
            ->where('company_id', $employee->company_id)
            ->where('rule_type', TimeCalculationEngine::RULE_TYPE_STANDARD_WORKWEEK)
            ->first();

        if ($laborRule === null) {
            throw new NoActiveLaborRuleVersionException(TimeCalculationEngine::RULE_TYPE_STANDARD_WORKWEEK, $employee->company_id, $date);
        }

        $ruleVersion = LaborRuleVersion::activeFor($laborRule->id, $date);

        if ($ruleVersion === null) {
            throw new NoActiveLaborRuleVersionException(TimeCalculationEngine::RULE_TYPE_STANDARD_WORKWEEK, $employee->company_id, $date);
        }

        return $ruleVersion;
    }

    /**
     * @return array{0: float, 1: float} monthly_hours_divisor and
     *                                   overtime_surcharge_pct, in that order.
     *
     * @throws MissingLaborRuleParameterException
     */
    private function requireOvertimeParameters(LaborRuleVersion $ruleVersion): array
    {
        $parameters = $ruleVersion->parameters;

        if (! array_key_exists('monthly_hours_divisor', $parameters)) {
            throw new MissingLaborRuleParameterException($ruleVersion->id, 'monthly_hours_divisor');
        }

        if (! array_key_exists('overtime_surcharge_pct', $parameters)) {
            throw new MissingLaborRuleParameterException($ruleVersion->id, 'overtime_surcharge_pct');
        }

        return [(float) $parameters['monthly_hours_divisor'], (float) $parameters['overtime_surcharge_pct']];
    }

    /**
     * Resolves the EmploymentContract in force for $employee on $date.
     * Employee::activeContractAt() returns null both before hire and when
     * an employment_contracts gap exists on that specific date; either way
     * there is no salary to derive an overtime rate from, which is the same
     * "cannot proceed without guessing" failure mode
     * resolveContractSubRanges() already documents for zero-contract
     * coverage — this reuses that exact same AmbiguousContractException
     * rather than inventing a second exception type for what is, from the
     * caller's perspective, the identical class of ambiguity (never guess
     * which contract/salary applies, per project rule #16).
     *
     * @throws AmbiguousContractException
     */
    private function resolveContractForOvertime(Employee $employee, CarbonInterface $date): EmploymentContract
    {
        $contract = $employee->activeContractAt($date);

        if ($contract === null) {
            throw new AmbiguousContractException($employee->id, $date);
        }

        return $contract;
    }
}
