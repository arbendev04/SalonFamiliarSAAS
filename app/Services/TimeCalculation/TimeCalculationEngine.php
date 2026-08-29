<?php

namespace App\Services\TimeCalculation;

use App\Exceptions\AmbiguousLaborRuleVersionException;
use App\Exceptions\MissingCriticalAttendanceEventException;
use App\Exceptions\MissingLaborRuleParameterException;
use App\Exceptions\NoActiveLaborRuleVersionException;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\LaborRule;
use App\Models\LaborRuleVersion;
use App\Models\NoveltyRecord;
use App\Models\OvertimeRecord;
use App\Models\Shift;
use App\Models\ShiftBreak;
use App\Models\TimeCalculationRun;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Cross-references a planned shift (shift_assignments+shift_breaks) against
 * real attendance (attendance_events net of approved attendance_adjustments,
 * via AttendanceNetEventsResolver) and the labor rule version in force to
 * produce ordinary/overtime-candidate/missing minutes for one employee/date,
 * persisted as an AttendanceRecord plus an immutable TimeCalculationRun
 * trace. See .ai/09-TIME-CALCULATION.md, this is the "no se recorta" core
 * of the module: it never assumes a default labor rule, never guesses a
 * missing critical event, and never modifies attendance_events (strictly
 * read-only over them, via the resolver).
 */
class TimeCalculationEngine
{
    /**
     * The only rule_type this phase resolves. Per the Fase 7 plan (YAGNI),
     * one company has at most one STANDARD_WORKWEEK labor rule; a
     * rule_type selector is deferred until a second type actually exists.
     */
    public const RULE_TYPE_STANDARD_WORKWEEK = 'STANDARD_WORKWEEK';

    public function __construct(
        private readonly AttendanceNetEventsResolver $netEventsResolver,
        private readonly NoveltyRecordLookup $noveltyLookup,
    ) {}

    /**
     * @throws AmbiguousLaborRuleVersionException
     * @throws MissingCriticalAttendanceEventException
     * @throws MissingLaborRuleParameterException
     * @throws NoActiveLaborRuleVersionException
     */
    public function calculateForDate(Employee $employee, Carbon $date): ?AttendanceRecord
    {
        $shift = $this->resolveAssignedShift($employee, $date);

        if ($shift === null) {
            return null;
        }

        $ruleVersion = $this->resolveActiveRuleVersion($employee, $date);
        [$toleranceMinutes, $roundingMinutes] = $this->resolveParameters($ruleVersion);

        $windowStart = Carbon::parse($shift->date->toDateString())->startOfDay();
        // Bounded strictly by shifts.date + crosses_midnight, per
        // .ai/09-TIME-CALCULATION.md "Turno que cruza medianoche": the
        // whole shift (including the portion after midnight) belongs to
        // one calendar date, never a tolerance-margin window invented here.
        $windowEnd = $windowStart->copy()->addDays($shift->crosses_midnight ? 2 : 1)->subSecond();

        $netEvents = $this->netEventsResolver->resolve($employee, $windowStart, $windowEnd);

        $plannedMinutes = $this->resolvePlannedMinutes($shift);

        $clockIn = $netEvents->firstWhere('event_type', 'clock_in');
        $clockOut = $netEvents->where('event_type', 'clock_out')->last();

        $isFullAbsence = $clockIn === null && $clockOut === null;

        if (! $isFullAbsence && $clockIn === null) {
            throw new MissingCriticalAttendanceEventException($employee->id, $date, 'clock_in');
        }

        if (! $isFullAbsence && $clockOut === null) {
            throw new MissingCriticalAttendanceEventException($employee->id, $date, 'clock_out');
        }

        $workedMinutes = $isFullAbsence
            ? 0
            : $this->resolveWorkedMinutes($netEvents, $clockIn, $clockOut);

        // Resolved unconditionally (not gated on $isFullAbsence) because
        // classify() itself is what decides whether the covering novelty is
        // acted upon this phase — see its docblock for the exact scoping.
        $coveringNovelty = $this->noveltyLookup->resolve($employee, $date);

        [$ordinaryMinutes, $overtimeCandidateMinutes, $missingMinutes, $justifiedMinutes] = $this->classify(
            $workedMinutes,
            $plannedMinutes,
            $toleranceMinutes,
            $roundingMinutes,
            $isFullAbsence,
            $coveringNovelty,
        );

        return DB::transaction(function () use (
            $employee,
            $date,
            $shift,
            $ruleVersion,
            $netEvents,
            $plannedMinutes,
            $workedMinutes,
            $clockIn,
            $clockOut,
            $ordinaryMinutes,
            $overtimeCandidateMinutes,
            $missingMinutes,
            $justifiedMinutes,
            $isFullAbsence,
            $coveringNovelty,
        ) {
            // justification_json only documents a novelty that actually
            // justified the day (the same $isFullAbsence && $coveringNovelty
            // !== null predicate classify() used for justified_minutes) —
            // never a novelty that merely overlaps a day with real clock
            // events, which this phase deliberately leaves unjustified (see
            // classify()'s docblock).
            $justificationJson = ($isFullAbsence && $coveringNovelty !== null)
                ? [
                    'novelty_record_id' => $coveringNovelty->id,
                    'novelty_type_code' => $coveringNovelty->noveltyType->code,
                ]
                : null;

            $record = AttendanceRecord::updateOrCreate(
                ['employee_id' => $employee->id, 'date' => $date->toDateString()],
                [
                    'company_id' => $employee->company_id,
                    'planned_json' => [
                        'shift_id' => $shift->id,
                        'planned_minutes' => $plannedMinutes,
                    ],
                    'worked_json' => [
                        'worked_minutes' => $workedMinutes,
                        'clock_in' => $clockIn !== null ? $clockIn['event_datetime']->toIso8601String() : null,
                        'clock_out' => $clockOut !== null ? $clockOut['event_datetime']->toIso8601String() : null,
                    ],
                    'ordinary_minutes' => $ordinaryMinutes,
                    'overtime_candidate_minutes' => $overtimeCandidateMinutes,
                    'missing_minutes' => $missingMinutes,
                    'justified_minutes' => $justifiedMinutes,
                    'justification_json' => $justificationJson,
                    'rule_version_id' => $ruleVersion->id,
                    'calculated_at' => now(),
                ],
            );

            TimeCalculationRun::create([
                'company_id' => $employee->company_id,
                'employee_id' => $employee->id,
                'date' => $date->toDateString(),
                'rule_version_id' => $ruleVersion->id,
                'inputs_hash' => $this->hashInputs($shift, $netEvents, $ruleVersion),
                'output_ref' => $record->id,
            ]);

            if ($overtimeCandidateMinutes > 0) {
                $existingOvertimeRecord = OvertimeRecord::query()
                    ->where('employee_id', $employee->id)
                    ->where('shift_id', $shift->id)
                    ->first();

                if ($existingOvertimeRecord === null) {
                    OvertimeRecord::create([
                        'company_id' => $employee->company_id,
                        'employee_id' => $employee->id,
                        'shift_id' => $shift->id,
                        'detected_minutes' => $overtimeCandidateMinutes,
                        'status' => 'detected',
                    ]);
                } elseif ($existingOvertimeRecord->status === 'detected') {
                    $existingOvertimeRecord->update(['detected_minutes' => $overtimeCandidateMinutes]);
                }
                // A record already at requested/authorized/rejected/paid is
                // NEVER touched by a recalculation — a human decision must
                // never be silently regressed by re-running the engine. See
                // App\Services\Overtime\OvertimeRecordService's docblock for
                // the full 4-state lifecycle this protects.
            }

            return $record;
        });
    }

    /**
     * @return Collection<int, array{date: string, status: string, record: ?AttendanceRecord, error: ?string}>
     */
    public function calculateForRange(Employee $employee, Carbon $startDate, Carbon $endDate): Collection
    {
        $results = collect();

        for ($date = $startDate->copy(); $date->lte($endDate); $date->addDay()) {
            $currentDate = $date->copy();

            try {
                $record = $this->calculateForDate($employee, $currentDate);

                $results->push([
                    'date' => $currentDate->toDateString(),
                    'status' => 'ok',
                    'record' => $record,
                    'error' => null,
                ]);
            } catch (NoActiveLaborRuleVersionException|AmbiguousLaborRuleVersionException|MissingLaborRuleParameterException|MissingCriticalAttendanceEventException $e) {
                $results->push([
                    'date' => $currentDate->toDateString(),
                    'status' => 'blocked',
                    'record' => null,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $results;
    }

    private function resolveAssignedShift(Employee $employee, Carbon $date): ?Shift
    {
        return Shift::query()
            ->where('company_id', $employee->company_id)
            ->where('date', $date->toDateString())
            ->whereHas('assignments', function ($query) use ($employee) {
                $query->where('employee_id', $employee->id)
                    ->where('status', '!=', 'cancelled');
            })
            ->with('breaks')
            ->first();
    }

    /**
     * @throws AmbiguousLaborRuleVersionException
     * @throws NoActiveLaborRuleVersionException
     */
    private function resolveActiveRuleVersion(Employee $employee, Carbon $date): LaborRuleVersion
    {
        $laborRule = LaborRule::query()
            ->where('company_id', $employee->company_id)
            ->where('rule_type', self::RULE_TYPE_STANDARD_WORKWEEK)
            ->first();

        if ($laborRule === null) {
            throw new NoActiveLaborRuleVersionException(self::RULE_TYPE_STANDARD_WORKWEEK, $employee->company_id, $date);
        }

        $ruleVersion = LaborRuleVersion::activeFor($laborRule->id, $date);

        if ($ruleVersion === null) {
            throw new NoActiveLaborRuleVersionException(self::RULE_TYPE_STANDARD_WORKWEEK, $employee->company_id, $date);
        }

        return $ruleVersion;
    }

    /**
     * @return array{0: int, 1: int}
     *
     * @throws MissingLaborRuleParameterException
     */
    private function resolveParameters(LaborRuleVersion $ruleVersion): array
    {
        $parameters = $ruleVersion->parameters;

        if (! array_key_exists('tolerance_minutes', $parameters)) {
            throw new MissingLaborRuleParameterException($ruleVersion->id, 'tolerance_minutes');
        }

        if (! array_key_exists('rounding_minutes', $parameters)) {
            throw new MissingLaborRuleParameterException($ruleVersion->id, 'rounding_minutes');
        }

        return [(int) $parameters['tolerance_minutes'], (int) $parameters['rounding_minutes']];
    }

    private function resolvePlannedMinutes(Shift $shift): int
    {
        $plannedGrossMinutes = $shift->start_datetime->diffInMinutes($shift->end_datetime);

        $plannedBreakMinutes = $shift->breaks->sum(
            fn (ShiftBreak $break): int => $break->planned_start->diffInMinutes($break->planned_end)
        );

        return $plannedGrossMinutes - $plannedBreakMinutes;
    }

    /**
     * Gross clock_in-to-clock_out minutes, minus only PAIRED
     * break_start/break_end minutes strictly within that window. An
     * unmatched break_start (no break_end before clock_out) is NOT
     * subtracted — those minutes count as worked. This is the PENDING
     * DECISION resolved in .ai/09-TIME-CALCULATION.md "Reglas" #6: the
     * blueprint only names clock_out as the critical event whose absence
     * blocks the calculation, so an unclosed break is implemented with the
     * simplest explicit criterion until revisited, rather than guessed.
     *
     * @param  Collection<int, array{event_id: string, event_type: string, event_datetime: Carbon}>  $netEvents
     * @param  array{event_id: string, event_type: string, event_datetime: Carbon}  $clockIn
     * @param  array{event_id: string, event_type: string, event_datetime: Carbon}  $clockOut
     */
    private function resolveWorkedMinutes(Collection $netEvents, array $clockIn, array $clockOut): int
    {
        $grossWorkedMinutes = $clockIn['event_datetime']->diffInMinutes($clockOut['event_datetime']);

        $pairedBreakMinutes = 0;
        $openBreakStart = null;

        foreach ($netEvents as $event) {
            if ($event['event_datetime']->lt($clockIn['event_datetime']) || $event['event_datetime']->gt($clockOut['event_datetime'])) {
                continue;
            }

            if ($event['event_type'] === 'break_start') {
                $openBreakStart = $event['event_datetime'];

                continue;
            }

            if ($event['event_type'] === 'break_end' && $openBreakStart !== null) {
                $pairedBreakMinutes += $openBreakStart->diffInMinutes($event['event_datetime']);
                $openBreakStart = null;
            }
        }

        return $grossWorkedMinutes - $pairedBreakMinutes;
    }

    /**
     * @return array{0: int, 1: int, 2: int, 3: int} Ordinary, overtime
     *                                               candidate, missing, and justified minutes, in that order.
     */
    private function classify(
        int $workedMinutes,
        int $plannedMinutes,
        int $toleranceMinutes,
        int $roundingMinutes,
        bool $isFullAbsence,
        ?NoveltyRecord $coveringNovelty,
    ): array {
        $diff = $workedMinutes - $plannedMinutes;

        $ordinaryMinutes = $this->roundToNearestMultiple(min($workedMinutes, $plannedMinutes), $roundingMinutes);

        $overtimeCandidateMinutes = $diff > 0
            ? $this->roundToNearestMultiple(max(0, $diff - $toleranceMinutes), $roundingMinutes)
            : 0;

        // Fase 8, section D of the plan: only a FULL-day absence can be
        // justified by an approved novelty this phase — the sole case
        // documented in .ai/09-TIME-CALCULATION.md "Casos especiales"
        // ("Permiso/ausencia aprobada sin marcación física"). A day with
        // SOME real clock events plus a covering novelty (e.g. the employee
        // clocked in anyway on a day an approved leave also covers) is
        // explicitly out of scope: no proration rule is invented for it, and
        // ordinary/overtime/missing classification proceeds exactly as if
        // the novelty were not there.
        if ($isFullAbsence && $coveringNovelty !== null) {
            $missingMinutes = 0;
            $justifiedMinutes = $this->roundToNearestMultiple($plannedMinutes, $roundingMinutes);
        } elseif ($isFullAbsence) {
            // Full absence is quantifiable (unlike a missing clock_out, it
            // is never ambiguous), and .ai/09-TIME-CALCULATION.md /
            // "Decisiones ya tomadas" fixes missing_minutes to the full
            // planned amount — tolerance is a grace margin around a real
            // marking, never a forgiveness window for a no-show.
            $missingMinutes = $this->roundToNearestMultiple($plannedMinutes, $roundingMinutes);
            $justifiedMinutes = 0;
        } else {
            $missingMinutes = $diff < 0
                ? $this->roundToNearestMultiple(max(0, abs($diff) - $toleranceMinutes), $roundingMinutes)
                : 0;
            $justifiedMinutes = 0;
        }

        return [$ordinaryMinutes, $overtimeCandidateMinutes, $missingMinutes, $justifiedMinutes];
    }

    private function roundToNearestMultiple(int $minutes, int $roundingMinutes): int
    {
        return (int) round($minutes / $roundingMinutes) * $roundingMinutes;
    }

    /**
     * A deterministic, debug-only trace hash — not a security control — of
     * the inputs that produced this calculation run.
     *
     * @param  Collection<int, array{event_id: string, event_type: string, event_datetime: Carbon}>  $netEvents
     */
    private function hashInputs(Shift $shift, Collection $netEvents, LaborRuleVersion $ruleVersion): string
    {
        return hash('sha256', json_encode([
            'shift_id' => $shift->id,
            'rule_version_id' => $ruleVersion->id,
            'net_events' => $netEvents->map(fn (array $event): array => [
                'event_id' => $event['event_id'],
                'event_type' => $event['event_type'],
                'event_datetime' => $event['event_datetime']->toIso8601String(),
            ])->all(),
        ]));
    }
}
