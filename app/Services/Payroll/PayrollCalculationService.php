<?php

namespace App\Services\Payroll;

use App\Exceptions\AmbiguousContractException;
use App\Exceptions\AmbiguousLaborRuleVersionException;
use App\Exceptions\AmbiguousSalaryHistoryException;
use App\Exceptions\InvalidPayrollPeriodStatusException;
use App\Exceptions\MissingLaborRuleParameterException;
use App\Exceptions\NoActiveLaborRuleVersionException;
use App\Exceptions\NoActiveSocialSecurityAffiliationException;
use App\Exceptions\NoAttendanceOrNoveltyDataException;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\EmploymentContract;
use App\Models\LaborRule;
use App\Models\LaborRuleVersion;
use App\Models\NoveltyRecord;
use App\Models\OvertimeRecord;
use App\Models\PayrollConceptDefinition;
use App\Models\PayrollDeductionPlan;
use App\Models\PayrollEntry;
use App\Models\PayrollPeriod;
use App\Models\SalaryHistory;
use App\Models\SocialSecurityAffiliation;
use App\Models\SocialSecurityConceptDefinition;
use App\Models\SocialSecurityContribution;
use App\Models\SocialSecurityEntity;
use App\Services\TimeCalculation\TimeCalculationEngine;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Liquidates devengado/deducido/neto per employee per payroll_periods, per
 * .ai/10-PAYROLL.md. Sin colaboradores por constructor (cálculo puro),
 * mirroring App\Services\TimeCalculation\TimeCalculationEngine.
 *
 * This commit adds the two public entry points — calculateForEmployee()/
 * calculateForPeriod() — that tie commits 6-9's pure computational helpers
 * (contract sub-range resolution, base-salary proration, authorized-overtime
 * translation, fixed-deduction translation) together with real persistence,
 * mirroring TimeCalculationEngine::calculateForDate()/calculateForRange()'s
 * exact structural template: compute everything first (cheapest/most-likely
 * to fail-fast checks first), then persist in one DB::transaction(), with a
 * per-unit try/catch at the batch level so one blocked employee never aborts
 * the rest of the period.
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
     * @return Collection<int, array{contract: EmploymentContract, from: Carbon, to: Carbon}>
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
     * @param  Collection<int, array{contract: EmploymentContract, from: Carbon, to: Carbon}>  $subRanges
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
     * Splits [$period->start_date, $period->end_date] into one sub-range per
     * social_security_affiliations row of the given $entityType overlapping
     * it, mirroring resolveContractSubRanges()'s exact overlap-query/clip
     * technique (composed-knitting-dusk.md, "Cálculo").
     *
     * Deliberately diverges from resolveContractSubRanges() in how it
     * treats a total absence of coverage: "zero employment_contracts
     * covering the period" is always AmbiguousContractException, because
     * every employee must have exactly one contract at all times. "Zero
     * social_security_affiliations of this $entityType", by contrast, is a
     * perfectly normal outcome — a company that has not yet configured this
     * entity_type or affiliated this employee to it can still liquidate the
     * rest of payroll fine, just without this concept's line (a later
     * commit's socialSecurityContributionLines() treats an empty result
     * from this method as "skip this concept for this employee", never as
     * a block). So this method returns an EMPTY collection, without ever
     * calling assertAffiliationSubRangesTilePeriodExactly(), when no
     * affiliation of $entityType overlaps the period at all.
     *
     * Once at least one affiliation of this $entityType DOES exist
     * somewhere in the period, though, every day of the period must be
     * covered by exactly one — a gap (including one at either edge of the
     * period) or an overlap between sub-ranges is exactly as much a
     * data-integrity failure as the contract case, so
     * assertAffiliationSubRangesTilePeriodExactly() is called to enforce
     * that, and can throw.
     *
     * @return Collection<int, array{affiliation: SocialSecurityAffiliation, from: Carbon, to: Carbon}>
     *
     * @throws NoActiveSocialSecurityAffiliationException
     */
    protected function resolveAffiliationSubRanges(Employee $employee, string $entityType, PayrollPeriod $period): Collection
    {
        $affiliations = SocialSecurityAffiliation::query()
            ->where('employee_id', $employee->id)
            ->where('entity_type', $entityType)
            ->where('start_date', '<=', $period->end_date->toDateString())
            ->where(function ($query) use ($period) {
                $query->whereNull('end_date')->orWhere('end_date', '>=', $period->start_date->toDateString());
            })
            ->orderBy('start_date')
            ->get();

        if ($affiliations->isEmpty()) {
            return collect();
        }

        $subRanges = $affiliations->map(function (SocialSecurityAffiliation $affiliation) use ($period): array {
            $from = $affiliation->start_date->greaterThan($period->start_date)
                ? $affiliation->start_date->copy()
                : $period->start_date->copy();

            $affiliationEnd = $affiliation->end_date ?? $period->end_date;
            $to = $affiliationEnd->lessThan($period->end_date)
                ? $affiliationEnd->copy()
                : $period->end_date->copy();

            return ['affiliation' => $affiliation, 'from' => $from, 'to' => $to];
        })->values();

        $this->assertAffiliationSubRangesTilePeriodExactly($subRanges, $period);

        return $subRanges;
    }

    /**
     * Walks the sub-ranges in order and confirms they tile the period with
     * no gap and no overlap — same walk as assertSubRangesTilePeriodExactly()
     * — but ONLY ever called (and only meaningful) when $subRanges is
     * non-empty: resolveAffiliationSubRanges() already short-circuits the
     * "zero affiliations of this entity_type at all" case into an empty
     * collection before this method runs, since that case is not an error
     * (see that method's docblock). A fully-empty $subRanges is therefore
     * never passed here from real production code.
     *
     * Once at least one affiliation exists somewhere in the period, a gap
     * or overlap between sub-ranges — including one that starts mid-period
     * or dangles before the period's end, since the plan does not carve out
     * a special "partial period is fine" case — reuses
     * NoActiveSocialSecurityAffiliationException, which its own docblock
     * already documents as covering both "zero" and "gap/overlap" with one
     * generic message, rather than inventing a bespoke partial-coverage
     * exception.
     *
     * employee_id/entity_type for the exception are read off the first
     * sub-range's own affiliation rather than accepted as separate
     * parameters — every row in $subRanges was queried for the same
     * employee/entity_type by construction, so the first one is
     * representative of all of them.
     *
     * Declared `protected`, not `private`, deliberately deviating from
     * assertSubRangesTilePeriodExactly()'s visibility for the same reason
     * authorizedOvertimeLines()/fixedDeductionLines() already do: this
     * commit's anonymous-subclass test wrapper needs direct access, and a
     * `private` method here would not be reachable from it.
     *
     * @param  Collection<int, array{affiliation: SocialSecurityAffiliation, from: Carbon, to: Carbon}>  $subRanges
     *
     * @throws NoActiveSocialSecurityAffiliationException
     */
    protected function assertAffiliationSubRangesTilePeriodExactly(Collection $subRanges, PayrollPeriod $period): void
    {
        /** @var SocialSecurityAffiliation $firstAffiliation */
        $firstAffiliation = $subRanges->first()['affiliation'];

        $expectedFrom = $period->start_date;

        foreach ($subRanges as $subRange) {
            if (! $subRange['from']->equalTo($expectedFrom)) {
                throw new NoActiveSocialSecurityAffiliationException($firstAffiliation->employee_id, $firstAffiliation->entity_type, $period->start_date);
            }

            $expectedFrom = $subRange['to']->copy()->addDay();
        }

        if (! $subRanges->last()['to']->equalTo($period->end_date)) {
            throw new NoActiveSocialSecurityAffiliationException($firstAffiliation->employee_id, $firstAffiliation->entity_type, $period->start_date);
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
     * @return Collection<int, array{concept_code: 'OVERTIME', quantity: float|int<0, max>, rate: float, amount: float}>
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

    /**
     * Translates the employee's active PayrollDeductionPlan rows (loans/
     * garnishments — plan section D/H) into DEDUCTION payroll_entry_lines
     * data. `PayrollDeductionPlan::scopeActiveFor()` already excludes any
     * plan with `remaining` = 0, so an employee with zero deduction plans —
     * or with only fully-paid-off ones — simply produces zero lines here,
     * which is entirely normal (not every employee has a loan or
     * garnishment), not an error.
     *
     * For each active plan, `amount = min(installment_amount, remaining)`:
     * a plan nearing its end might have less remaining than a full
     * installment, and this method must never deduct more than what is
     * actually owed.
     *
     * IMPORTANT — this method does NOT mutate `remaining`. It only computes
     * what WOULD be deducted for the current calculation; the actual
     * decrement happens exclusively in PayrollPeriodService::close() (a
     * later commit). This is deliberate: an OPEN/CALCULATED period can be
     * recalculated multiple times before it is closed, and recalculating
     * must never double-consume installment balance. `plan_id` is carried
     * in the returned array purely so that later close()-time wiring — which
     * needs to know exactly which plan row to decrement — has it on hand
     * without an extra lookup.
     *
     * Declared `protected`, not `private` (the plan sketch's literal
     * visibility), to stay consistent with every other testable
     * computational helper already in this class — see
     * authorizedOvertimeLines()'s docblock for why: a `private` method here
     * would not be reachable from the anonymous-subclass test wrapper this
     * file's tests use.
     *
     * @return Collection<int, array{concept_id: string, plan_id: string, quantity: null, rate: null, amount: float}>
     */
    protected function fixedDeductionLines(Employee $employee): Collection
    {
        return PayrollDeductionPlan::query()
            ->activeFor($employee->id)
            ->get()
            ->map(function (PayrollDeductionPlan $plan): array {
                $amount = min((float) $plan->installment_amount, (float) $plan->remaining);

                return [
                    'concept_id' => $plan->concept_id,
                    'plan_id' => $plan->id,
                    'quantity' => null,
                    'rate' => null,
                    'amount' => $amount,
                ];
            })
            ->values();
    }

    /**
     * Resolves the active LaborRuleVersion carrying the versioned rate for
     * one social-security concept, per composed-knitting-dusk.md ADR-020
     * ("nunca una copia paralela de los mismos porcentajes" — reuse
     * labor_rules/labor_rule_versions rather than a parallel table) and the
     * exact `rule_type = 'SOCIAL_SECURITY_' . $conceptCode` convention
     * SocialSecurityRuleVersionController::resolveLaborRule() already
     * establishes for writes.
     *
     * Deliberately takes `Employee $employee` rather than a bare company id
     * string, mirroring resolveActiveLaborRuleVersion()'s own signature —
     * this class has no CurrentCompany collaborator injected anywhere else,
     * so `$employee->company_id` is how every other method here scopes a
     * tenant-specific lookup.
     *
     * Distinguishes two failure modes that resolveActiveLaborRuleVersion()
     * collapses into one for STANDARD_WORKWEEK (where a labor_rules row is
     * assumed to always exist by platform convention):
     *   - No `labor_rules` row at all for this rule_type: the concept has no
     *     rate configured yet at all. This is the normal state for a
     *     freshly-created SocialSecurityConceptDefinition whose rate
     *     management screen was never visited — returns null as a "skip
     *     this concept" signal, never an error, consistent with how a
     *     company with zero SocialSecurityConceptDefinition rows configured
     *     at all already skips cleanly (design decision #2).
     *   - A `labor_rules` row exists but LaborRuleVersion::activeFor() finds
     *     no version covering $date: unlike the above, the company DID
     *     start configuring this concept's rate, so a rate gap for a date
     *     that already has an affiliation is a genuine blocking
     *     configuration failure — throws NoActiveLaborRuleVersionException,
     *     never silently skipped.
     *
     * @throws NoActiveLaborRuleVersionException
     * @throws AmbiguousLaborRuleVersionException
     */
    protected function resolveActiveSocialSecurityRuleVersion(Employee $employee, string $conceptCode, CarbonInterface $date): ?LaborRuleVersion
    {
        $ruleType = 'SOCIAL_SECURITY_'.$conceptCode;

        $laborRule = LaborRule::query()
            ->where('company_id', $employee->company_id)
            ->where('rule_type', $ruleType)
            ->first();

        if ($laborRule === null) {
            return null;
        }

        $ruleVersion = LaborRuleVersion::activeFor($laborRule->id, $date);

        if ($ruleVersion === null) {
            throw new NoActiveLaborRuleVersionException($ruleType, $employee->company_id, $date);
        }

        return $ruleVersion;
    }

    /**
     * Validates and extracts the three parameters a social-security rate
     * version must carry, per composed-knitting-dusk.md design decision #1:
     * `employee_pct`/`employer_pct` (0..1 floats) and `base_concept_codes`
     * (an array of payroll_concept_definitions.code values whose summed
     * line amounts form this concept's contribution base). Mirrors
     * requireOvertimeParameters()'s exact validate-then-return-tuple shape.
     *
     * @return array{0: float, 1: float, 2: array<int, string>} employee_pct, employer_pct, base_concept_codes, in that order.
     *
     * @throws MissingLaborRuleParameterException
     */
    protected function requireSocialSecurityRateParameters(LaborRuleVersion $version): array
    {
        $parameters = $version->parameters;

        if (! array_key_exists('employee_pct', $parameters)) {
            throw new MissingLaborRuleParameterException($version->id, 'employee_pct');
        }

        if (! array_key_exists('employer_pct', $parameters)) {
            throw new MissingLaborRuleParameterException($version->id, 'employer_pct');
        }

        if (! array_key_exists('base_concept_codes', $parameters)) {
            throw new MissingLaborRuleParameterException($version->id, 'base_concept_codes');
        }

        return [
            (float) $parameters['employee_pct'],
            (float) $parameters['employer_pct'],
            $parameters['base_concept_codes'],
        ];
    }

    /**
     * Computes social-security contribution lines for one employee/period,
     * per composed-knitting-dusk.md's "Cálculo" section. Iterates every
     * SocialSecurityConceptDefinition effective for the employee's company
     * (SocialSecurityConceptDefinition::effectiveForCompany() — platform
     * default OR company override, same HasPlatformOrCompanyDefault
     * convention as resolveConceptId() already follows for the payroll
     * catalog). Zero concepts configured is completely normal — a company
     * that has not set up social security yet still calculates the rest of
     * payroll fine, per design decision #2 — never an error, so this method
     * simply returns an empty Collection in that case.
     *
     * For each concept: resolveAffiliationSubRanges() partitions the period
     * into sub-ranges of SocialSecurityAffiliation for that concept's
     * `entity_type`. An EMPTY result means this employee is not (yet)
     * affiliated to that entity_type at all — skip this concept entirely,
     * never an error (same method's own docblock). A NON-EMPTY result has
     * already been validated internally by resolveAffiliationSubRanges()
     * (which calls assertAffiliationSubRangesTilePeriodExactly() itself
     * before returning) to tile the period with no gap/overlap, so no
     * second assertion call is needed here — any gap/overlap already threw
     * NoActiveSocialSecurityAffiliationException before this method ever
     * sees the result.
     *
     * For each sub-range: resolves the LaborRuleVersion active for this
     * concept's rate as of the SUB-RANGE'S OWN start date — never the
     * period's start — mirroring resolveDailyRate()'s established
     * per-sub-range date-resolution convention for time-varying parameters.
     * A null result (no labor_rules row for this concept at all) skips this
     * sub-range's contribution line rather than blocking, per
     * resolveActiveSocialSecurityRuleVersion()'s own "skip" contract.
     * Otherwise validates the version's three required parameters via
     * requireSocialSecurityRateParameters() and computes:
     *
     *   base_amount     = sum($earningLines matching base_concept_codes)
     *                      * (calendar_days_in_sub_range / calendar_days_in_period)
     *   employee_amount = round(base_amount * employee_pct, 2)
     *   employer_amount = round(base_amount * employer_pct, 2)
     *
     * — the provisional day-weighted mid-period proration criterion from
     * design decision #5, documented there as a still-open PENDING DECISION
     * (never a legally-validated formula), deliberately simpler than
     * re-cutting against employment_contracts' own sub-ranges. Money is
     * rounded to 2 decimals via round(), the same convention used
     * throughout this file (see e.g. fixedDeductionLines()).
     *
     * `base_concept_codes` entries are resolved to payroll_entry_lines'
     * actual `concept_id` via resolveConceptId() (never a bare code
     * comparison) so they can be matched against $earningLines' already-
     * resolved concept ids.
     *
     * $earningLines is the caller's ALREADY-COMPUTED collection of this
     * employee/period's earning lines (base salary + authorized overtime),
     * each entry annotated with its resolved payroll_concept_definitions id
     * — i.e. `['concept_id' => string, 'amount' => float, ...]` — NOT
     * proratedBaseSalaryLines()'s raw `contract_id`-keyed shape nor
     * authorizedOvertimeLines()'s raw `concept_code`-keyed shape. Building
     * this normalized shape is the caller's responsibility (a later commit
     * wiring this into calculateForEmployee()), exactly as
     * calculateForEmployee() already resolves $baseSalaryConceptId/
     * $overtimeConceptId separately from proratedBaseSalaryLines()'s/
     * authorizedOvertimeLines()'s own return shapes before persisting.
     *
     * Return shape — one entry per (concept × affiliation sub-range)
     * combination, documented here because a later commit's persistence
     * wiring depends on it exactly:
     *   - concept: the SocialSecurityConceptDefinition this line belongs to.
     *   - entity: the SocialSecurityEntity of the affiliation sub-range this
     *     line was prorated against (the employee's affiliated entity for
     *     that sub-range, NOT necessarily the same entity across every
     *     sub-range of the same concept — a mid-period entity change is
     *     exactly what produces more than one line for the same concept).
     *   - period_from/period_to: the sub-range's own clipped bounds.
     *   - base_amount/employee_amount/employer_amount: this sub-range's
     *     share of the period's base and the two computed contribution
     *     amounts.
     *
     * @param  Collection<int, array{concept_id: string, amount: float}>  $earningLines
     * @return Collection<int, array{concept: SocialSecurityConceptDefinition, entity: SocialSecurityEntity, period_from: CarbonInterface, period_to: CarbonInterface, base_amount: float, employee_amount: float, employer_amount: float}>
     *
     * @throws NoActiveSocialSecurityAffiliationException
     * @throws NoActiveLaborRuleVersionException
     * @throws AmbiguousLaborRuleVersionException
     * @throws MissingLaborRuleParameterException
     */
    protected function socialSecurityContributionLines(Employee $employee, PayrollPeriod $period, Collection $earningLines): Collection
    {
        $periodDays = $period->start_date->diffInDays($period->end_date) + 1;

        $concepts = SocialSecurityConceptDefinition::query()->effectiveForCompany($employee->company_id)->get();

        $lines = collect();

        foreach ($concepts as $concept) {
            $subRanges = $this->resolveAffiliationSubRanges($employee, $concept->entity_type, $period);

            if ($subRanges->isEmpty()) {
                continue;
            }

            foreach ($subRanges as $subRange) {
                /** @var SocialSecurityAffiliation $affiliation */
                $affiliation = $subRange['affiliation'];
                /** @var CarbonInterface $from */
                $from = $subRange['from'];
                /** @var CarbonInterface $to */
                $to = $subRange['to'];

                $ruleVersion = $this->resolveActiveSocialSecurityRuleVersion($employee, $concept->code, $from);

                if ($ruleVersion === null) {
                    continue;
                }

                [$employeePct, $employerPct, $baseConceptCodes] = $this->requireSocialSecurityRateParameters($ruleVersion);

                $baseConceptIds = collect($baseConceptCodes)
                    ->map(fn (string $code): string => $this->resolveConceptId($employee->company_id, $code));

                $periodBaseTotal = $earningLines
                    ->filter(fn (array $line): bool => $baseConceptIds->contains($line['concept_id']))
                    ->sum('amount');

                $subRangeDays = $from->diffInDays($to) + 1;
                $baseAmount = $periodBaseTotal * ($subRangeDays / $periodDays);

                $lines->push([
                    'concept' => $concept,
                    'entity' => $affiliation->entity,
                    'period_from' => $from,
                    'period_to' => $to,
                    'base_amount' => round($baseAmount, 2),
                    'employee_amount' => round($baseAmount * $employeePct, 2),
                    'employer_amount' => round($baseAmount * $employerPct, 2),
                ]);
            }
        }

        return $lines->values();
    }

    /**
     * Computes and persists the full settlement for one employee/period,
     * per .ai/10-PAYROLL.md plan section D. Runs the four computational
     * guards/translators in cheapest/most-likely-to-fail-fast order — the
     * same ordering discipline TimeCalculationEngine::calculateForDate()
     * uses for its own guards — BEFORE opening any transaction, so none of
     * the six documented exceptions can ever fire mid-write:
     *
     *   1. assertHasAttendanceOrNoveltyCoverage() — cheapest, most
     *      fundamental gate (no attendance/novelty data at all makes every
     *      other computation moot).
     *   2. proratedBaseSalaryLines() — also validates contract coverage via
     *      resolveContractSubRanges() internally.
     *   3. authorizedOvertimeLines().
     *   4. fixedDeductionLines() — never throws.
     *
     * BASE_SALARY/OVERTIME concept ids are resolved via
     * PayrollConceptDefinition::effectiveForCompany() (never a bare
     * ->where('code', ...) on the model directly) because both are
     * platform-default (company_id=null) catalog rows — the exact
     * BelongsToCompany global-scope exclusion bug documented on
     * HasPlatformOrCompanyDefault (and that silently broke LeaveRecordService
     * in Fase 8) would otherwise make them unreachable once a tenant is
     * active.
     *
     * Immediately after fixedDeductionLines(), and still before the
     * transaction opens, socialSecurityContributionLines() is computed too
     * (composed-knitting-dusk.md, "Punto de integración en
     * calculateForEmployee()"). It needs $earningLines — this employee/
     * period's BASE_SALARY and OVERTIME line amounts, each annotated with
     * its resolved payroll_concept_definitions id — which
     * proratedBaseSalaryLines()/authorizedOvertimeLines() do not carry
     * themselves, so it is built here by re-using the same
     * $baseSalaryConceptId/$overtimeConceptId this method already resolves
     * for persistence, never a second/parallel resolution mechanism.
     * NoActiveSocialSecurityAffiliationException joins the same six-way
     * catch below: a social-security gap or rate-configuration problem for
     * ONE employee blocks only that employee, exactly like every other
     * documented exception here.
     *
     * Persistence happens in ONE DB::transaction(): updateOrCreate() the
     * PayrollEntry (full replace, never an incremental patch — same
     * "always regenerate completely" discipline as AttendanceRecord), then
     * delete ALL of its existing payroll_entry_lines and recreate them fresh
     * from the computed line sets. This is safe to call repeatedly
     * for the same employee/period (e.g. after an underlying
     * AttendanceRecord changes) — see the unique constraint on
     * (payroll_period_id, employee_id). The same full-replace discipline
     * applies to social_security_contributions: any contribution rows from
     * a previous calculation of this entry are deleted (only reachable
     * after their paired lines are already gone, since
     * payroll_entry_lines.social_security_contribution_id is
     * restrictOnDelete) before the fresh set from
     * socialSecurityContributionLines() is inserted — one
     * SocialSecurityContribution + one paired PayrollEntryLine per (concept
     * × affiliation sub-range), `concept_id` on the line always the generic
     * SOCIAL_SECURITY payroll-catalog concept, `amount` always the
     * sub-range's `employee_amount`. `employer_amount` is stored only on
     * SocialSecurityContribution — it is employer cost, not an employee-
     * facing payroll line, so it never produces a payroll_entry_line of its
     * own.
     *
     * If any of the seven documented exceptions is thrown — whether from the
     * pre-transaction computation above, or (defensively) from inside the
     * transaction itself — this method persists a status='blocked'
     * PayrollEntry (contract_id=null, all totals 0, zero lines) and
     * RE-THROWS the original exception: the caller (calculateForPeriod())
     * needs to know both that this employee was blocked AND why, but the
     * blocked row must still exist in the DB for PayrollPeriodService::
     * close() to detect later.
     *
     * A thrown exception inside a DB::transaction() closure rolls that
     * transaction back automatically — so the blocked-entry write can never
     * happen INSIDE the same transaction that just failed (a rolled-back
     * transaction can't accept more writes as part of the same unit of
     * work). persistBlockedEntry() therefore always runs in its own, fresh
     * DB::transaction(), opened only in the catch block, strictly AFTER the
     * first transaction's implicit rollback has already completed.
     *
     * Defense in depth for the phase's core acceptance criterion — "el
     * cierre de un periodo es efectivamente inmutable a nivel de
     * aplicación" — this class is otherwise a pure calculation engine with
     * NO knowledge of period-level state, but that immutability guarantee
     * cannot depend solely on PayrollPeriodService::calculate()'s own
     * status guard: anything that ever calls this method directly (a
     * scheduled job, a console command, a bug in some other caller) would
     * silently bypass that guard entirely and freely delete/recreate a
     * closed period's entry lines. This check runs BEFORE every other
     * guarded computational step — including
     * assertHasAttendanceOrNoveltyCoverage() — so a closed period is
     * rejected immediately and cheaply, never after doing wasted work.
     *
     * @throws InvalidPayrollPeriodStatusException
     * @throws AmbiguousContractException
     * @throws AmbiguousSalaryHistoryException
     * @throws NoActiveLaborRuleVersionException
     * @throws AmbiguousLaborRuleVersionException
     * @throws MissingLaborRuleParameterException
     * @throws NoAttendanceOrNoveltyDataException
     * @throws NoActiveSocialSecurityAffiliationException
     */
    public function calculateForEmployee(PayrollPeriod $period, Employee $employee): PayrollEntry
    {
        if ($period->status === 'closed') {
            throw new InvalidPayrollPeriodStatusException($period->id, $period->status, 'open|calculated|approved|reopened');
        }

        try {
            $this->assertHasAttendanceOrNoveltyCoverage($employee, $period);

            $baseSalary = $this->proratedBaseSalaryLines($employee, $period);
            $overtimeLines = $this->authorizedOvertimeLines($employee, $period);
            $deductionLines = $this->fixedDeductionLines($employee);

            $baseSalaryConceptId = $this->resolveConceptId($employee->company_id, 'BASE_SALARY');
            $overtimeConceptId = $this->resolveConceptId($employee->company_id, 'OVERTIME');
            $socialSecurityConceptId = $this->resolveConceptId($employee->company_id, 'SOCIAL_SECURITY');

            $earningLines = $baseSalary['lines']
                ->map(fn (array $line): array => ['concept_id' => $baseSalaryConceptId, 'amount' => $line['amount']])
                ->concat($overtimeLines->map(fn (array $line): array => ['concept_id' => $overtimeConceptId, 'amount' => $line['amount']]));

            $socialSecurityLines = $this->socialSecurityContributionLines($employee, $period, $earningLines);

            $grossTotal = $baseSalary['lines']->sum('amount') + $overtimeLines->sum('amount');
            $deductionsTotal = $deductionLines->sum('amount') + $socialSecurityLines->sum('employee_amount');
            $netTotal = $grossTotal - $deductionsTotal;

            return DB::transaction(function () use (
                $period,
                $employee,
                $baseSalary,
                $overtimeLines,
                $deductionLines,
                $baseSalaryConceptId,
                $overtimeConceptId,
                $socialSecurityConceptId,
                $socialSecurityLines,
                $grossTotal,
                $deductionsTotal,
                $netTotal,
            ): PayrollEntry {
                $entry = PayrollEntry::updateOrCreate(
                    ['payroll_period_id' => $period->id, 'employee_id' => $employee->id],
                    [
                        'company_id' => $employee->company_id,
                        'contract_id' => $baseSalary['last_contract']->id,
                        'status' => 'calculated',
                        'gross_total' => $grossTotal,
                        'deductions_total' => $deductionsTotal,
                        'net_total' => $netTotal,
                    ],
                );

                $entry->lines()->delete();

                // Only reachable now that this entry's lines are gone —
                // payroll_entry_lines.social_security_contribution_id is
                // restrictOnDelete, so a previous calculation's contribution
                // rows could not be removed before their paired lines were.
                SocialSecurityContribution::query()->where('payroll_entry_id', $entry->id)->delete();

                foreach ($baseSalary['lines'] as $line) {
                    $entry->lines()->create([
                        'company_id' => $employee->company_id,
                        'concept_id' => $baseSalaryConceptId,
                        'contract_id' => $line['contract_id'],
                        'type' => 'earning',
                        'quantity' => $line['quantity'],
                        'rate' => $line['rate'],
                        'amount' => $line['amount'],
                    ]);
                }

                foreach ($overtimeLines as $line) {
                    $entry->lines()->create([
                        'company_id' => $employee->company_id,
                        'concept_id' => $overtimeConceptId,
                        'contract_id' => null,
                        'type' => 'earning',
                        'quantity' => $line['quantity'],
                        'rate' => $line['rate'],
                        'amount' => $line['amount'],
                    ]);
                }

                foreach ($deductionLines as $line) {
                    $entry->lines()->create([
                        'company_id' => $employee->company_id,
                        'concept_id' => $line['concept_id'],
                        'contract_id' => null,
                        // Carries fixedDeductionLines()'s plan_id through to
                        // persistence so PayrollPeriodService::close() can
                        // trace this line back to the PayrollDeductionPlan
                        // whose `remaining` it must decrement.
                        'deduction_plan_id' => $line['plan_id'],
                        'type' => 'deduction',
                        'quantity' => $line['quantity'],
                        'rate' => $line['rate'],
                        'amount' => $line['amount'],
                    ]);
                }

                foreach ($socialSecurityLines as $line) {
                    $contribution = SocialSecurityContribution::create([
                        'company_id' => $employee->company_id,
                        'payroll_entry_id' => $entry->id,
                        'entity_id' => $line['entity']->id,
                        'concept_id' => $line['concept']->id,
                        'period_from' => $line['period_from'],
                        'period_to' => $line['period_to'],
                        'base_amount' => $line['base_amount'],
                        'employee_amount' => $line['employee_amount'],
                        'employer_amount' => $line['employer_amount'],
                    ]);

                    // employer_amount deliberately produces NO line here —
                    // it is employer cost, not an employee-facing deduction;
                    // it stays traceable solely via the SocialSecurityContribution
                    // row just created above.
                    $entry->lines()->create([
                        'company_id' => $employee->company_id,
                        'concept_id' => $socialSecurityConceptId,
                        'contract_id' => null,
                        'social_security_contribution_id' => $contribution->id,
                        'type' => 'deduction',
                        'quantity' => null,
                        'rate' => null,
                        'amount' => $line['employee_amount'],
                    ]);
                }

                return $entry;
            });
        } catch (AmbiguousContractException|AmbiguousSalaryHistoryException|NoActiveLaborRuleVersionException|AmbiguousLaborRuleVersionException|MissingLaborRuleParameterException|NoAttendanceOrNoveltyDataException|NoActiveSocialSecurityAffiliationException $e) {
            $this->persistBlockedEntry($period, $employee);

            throw $e;
        }
    }

    /**
     * Persists a status='blocked' PayrollEntry for one employee/period —
     * contract_id=null, all three totals at 0, and its lines fully cleared
     * — so that PayrollPeriodService::close() (a later commit) can detect
     * unresolved blocked employees without depending solely on the
     * transient batch summary calculateForPeriod() returns. Always runs in
     * its own fresh transaction; see calculateForEmployee()'s docblock for
     * why this can never share a transaction with the failed calculation
     * attempt that triggered it.
     */
    private function persistBlockedEntry(PayrollPeriod $period, Employee $employee): void
    {
        DB::transaction(function () use ($period, $employee): void {
            $entry = PayrollEntry::updateOrCreate(
                ['payroll_period_id' => $period->id, 'employee_id' => $employee->id],
                [
                    'company_id' => $employee->company_id,
                    'contract_id' => null,
                    'status' => 'blocked',
                    'gross_total' => 0,
                    'deductions_total' => 0,
                    'net_total' => 0,
                ],
            );

            $entry->lines()->delete();
        });
    }

    /**
     * Resolves a payroll concept definition's id by code for the given
     * company, via PayrollConceptDefinition::effectiveForCompany() —
     * NEVER a bare ->where('code', ...) on the model directly. BASE_SALARY
     * and OVERTIME are seeded as platform defaults (company_id=null) by
     * PayrollConceptCatalogSeeder; a bare query would silently exclude them
     * once BelongsToCompany's global tenant scope is active, the exact
     * documented bug that already broke LeaveRecordService in Fase 8 — see
     * HasPlatformOrCompanyDefault's docblock.
     *
     * A missing code here is a platform seeding/configuration failure, not
     * a per-employee data ambiguity — it deliberately does NOT reuse any of
     * the six documented blocking exceptions and is never caught by
     * calculateForEmployee()'s per-employee catch, since it is not
     * something recalculating this one employee could ever resolve.
     */
    private function resolveConceptId(?string $companyId, string $code): string
    {
        $concept = PayrollConceptDefinition::query()
            ->effectiveForCompany($companyId)
            ->where('code', $code)
            ->first();

        if ($concept === null) {
            throw new RuntimeException("No existe una definición de concepto de nómina con código '{$code}' visible para la empresa {$companyId}: verifique que PayrollConceptCatalogSeeder se haya ejecutado.");
        }

        return $concept->id;
    }

    /**
     * Iterates every employee in the period's company and calculates each
     * one independently, mirroring TimeCalculationEngine::calculateForRange()'s
     * exact try/catch-and-collect shape: one employee's blocking exception
     * never aborts the rest of the batch. By the time this method returns,
     * every employee has either an 'ok' PayrollEntry or a 'blocked' one
     * persisted in the DB — calculateForEmployee() guarantees the latter via
     * persistBlockedEntry() before rethrowing, so the blocked row is simply
     * looked up here rather than recreated.
     *
     * InvalidPayrollPeriodStatusException is deliberately NOT added to this
     * per-employee catch list. It is not a per-employee data problem the
     * way the other six documented exceptions are — the period-level
     * status check inside calculateForEmployee() does not vary by
     * employee, so if it fires for one employee it would fire identically
     * for every other employee in the batch. Catching it here would mean
     * silently marking the ENTIRE company's employee roster 'blocked' with
     * the same repeated message, which would misrepresent what is actually
     * a single caller-level mistake (calling calculateForPeriod() on a
     * closed period at all) as a batch of unrelated per-employee failures.
     * Instead it propagates immediately out of this method on the very
     * first employee it hits, aborting the whole batch — the same
     * "caller's precondition was wrong from the start" signal
     * PayrollPeriodService::calculate()'s own upfront guard already gives
     * for this exact case.
     *
     * @return Collection<int, array{employee_id: string, status: 'ok'|'blocked', entry: ?PayrollEntry, error: ?string}>
     *
     * @throws InvalidPayrollPeriodStatusException
     */
    public function calculateForPeriod(PayrollPeriod $period): Collection
    {
        $employees = Employee::query()->where('company_id', $period->company_id)->get();

        $results = collect();

        foreach ($employees as $employee) {
            try {
                $entry = $this->calculateForEmployee($period, $employee);

                $results->push([
                    'employee_id' => $employee->id,
                    'status' => 'ok',
                    'entry' => $entry,
                    'error' => null,
                ]);
            } catch (AmbiguousContractException|AmbiguousSalaryHistoryException|NoActiveLaborRuleVersionException|AmbiguousLaborRuleVersionException|MissingLaborRuleParameterException|NoAttendanceOrNoveltyDataException|NoActiveSocialSecurityAffiliationException $e) {
                $blockedEntry = PayrollEntry::query()
                    ->where('payroll_period_id', $period->id)
                    ->where('employee_id', $employee->id)
                    ->first();

                $results->push([
                    'employee_id' => $employee->id,
                    'status' => 'blocked',
                    'entry' => $blockedEntry,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $results;
    }
}
