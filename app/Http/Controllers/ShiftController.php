<?php

namespace App\Http\Controllers;

use App\Http\Requests\GenerateShiftsRequest;
use App\Http\Requests\StoreShiftRequest;
use App\Models\Employee;
use App\Models\Shift;
use App\Models\WorkScheduleTemplate;
use App\Services\Shifts\ShiftGenerator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ShiftController extends Controller
{
    public function index(Employee $employee): Response
    {
        Gate::authorize('employees.read');

        $shifts = Shift::query()
            ->whereHas('assignments', fn ($query) => $query
                ->where('employee_id', $employee->id)
                ->where('status', '!=', 'cancelled'))
            ->orderByDesc('start_datetime')
            ->get();

        return Inertia::render('employees/Shifts', [
            'employee' => $employee->only(['id', 'full_name']),
            'shifts' => $shifts->map(fn (Shift $shift) => [
                'id' => $shift->id,
                'date' => Carbon::parse($shift->date)->toDateString(),
                'start_datetime' => Carbon::parse($shift->start_datetime)->toDateTimeString(),
                'end_datetime' => Carbon::parse($shift->end_datetime)->toDateTimeString(),
                'crosses_midnight' => $shift->crosses_midnight,
                'source' => $shift->source,
            ]),
            'canManageSchedules' => Gate::allows('schedules.write'),
            'templates' => WorkScheduleTemplate::query()->orderBy('name')->get(['id', 'name']),
            'employees' => Employee::query()->orderBy('full_name')->get(['id', 'full_name']),
        ]);
    }

    public function generate(GenerateShiftsRequest $request, Employee $employee, ShiftGenerator $generator): RedirectResponse
    {
        $generated = $generator->generate(
            $employee,
            Carbon::parse($request->validated('start_date')),
            Carbon::parse($request->validated('end_date')),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => "Se generaron {$generated->count()} turnos."]);

        return back();
    }

    public function store(StoreShiftRequest $request, Employee $employee): RedirectResponse
    {
        DB::transaction(function () use ($request, $employee) {
            $shift = Shift::create([
                ...$request->validated(),
                'source' => 'manual',
            ]);

            $shift->assignments()->create([
                'company_id' => $shift->company_id,
                'employee_id' => $employee->id,
                'status' => 'assigned',
            ]);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Turno agregado.']);

        return back();
    }
}
