<?php

namespace App\Http\Controllers;

use App\Http\Requests\RecalculateAttendanceRequest;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Services\TimeCalculation\TimeCalculationEngine;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class AttendanceRecordController extends Controller
{
    public function index(Employee $employee): Response
    {
        Gate::authorize('time_calculation.read');

        $records = $employee->attendanceRecords()
            ->orderByDesc('date')
            ->with('ruleVersion')
            ->get();

        return Inertia::render('employees/TimeCalculation', [
            'employee' => $employee->only(['id', 'full_name']),
            'records' => $records->map(fn (AttendanceRecord $record) => [
                'id' => $record->id,
                'date' => $record->date->toDateString(),
                'planned_json' => $record->planned_json,
                'worked_json' => $record->worked_json,
                'ordinary_minutes' => $record->ordinary_minutes,
                'overtime_candidate_minutes' => $record->overtime_candidate_minutes,
                'missing_minutes' => $record->missing_minutes,
                'calculated_at' => $record->calculated_at?->toDateTimeString(),
            ]),
            'canCalculate' => Gate::allows('time_calculation.calculate'),
        ]);
    }

    /**
     * Triggers an on-demand recalculation over a date range (see .ai
     * 09-TIME-CALCULATION.md, "Disparo de recálculo"). No try/catch is
     * needed here: TimeCalculationEngine::calculateForRange() already
     * catches its own four documented blocking exceptions
     * (AmbiguousLaborRuleVersionException, MissingCriticalAttendanceEventException,
     * MissingLaborRuleParameterException, NoActiveLaborRuleVersionException)
     * per date and returns a {status: ok|blocked} summary instead of
     * throwing them — verified by re-reading the method. Any other
     * exception (e.g. a database failure) is left to bubble to the default
     * error handler, same as every other write endpoint in this codebase.
     */
    public function recalculate(RecalculateAttendanceRequest $request, Employee $employee, TimeCalculationEngine $engine): RedirectResponse
    {
        $results = $engine->calculateForRange(
            $employee,
            Carbon::parse($request->validated('start_date')),
            Carbon::parse($request->validated('end_date')),
        );

        $okCount = $results->where('status', 'ok')->count();
        $blockedCount = $results->where('status', 'blocked')->count();

        $message = $blockedCount > 0
            ? "Se calcularon {$okCount} fechas, {$blockedCount} quedaron bloqueadas — ver detalle."
            : "Se calcularon {$okCount} fechas.";

        Inertia::flash('toast', [
            'type' => $blockedCount > 0 ? 'warning' : 'success',
            'message' => $message,
        ]);

        return back();
    }
}
