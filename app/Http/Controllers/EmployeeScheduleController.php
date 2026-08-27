<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreEmployeeScheduleRequest;
use App\Models\Employee;
use App\Models\EmployeeSchedule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class EmployeeScheduleController extends Controller
{
    public function store(StoreEmployeeScheduleRequest $request, Employee $employee): RedirectResponse
    {
        DB::transaction(function () use ($request, $employee) {
            $effectiveFrom = Carbon::parse($request->validated('effective_from'));
            $dayBefore = $effectiveFrom->copy()->subDay();

            // .ai/08-SHIFTS.md: assigning a new template closes the
            // previous assignment instead of overwriting it.
            $current = $employee->activeScheduleAt($dayBefore);

            if ($current) {
                $current->update(['effective_to' => $dayBefore->toDateString()]);
            }

            EmployeeSchedule::create([
                'employee_id' => $employee->id,
                'template_id' => $request->validated('template_id'),
                'effective_from' => $effectiveFrom->toDateString(),
            ]);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Jornada asignada.']);

        return back();
    }
}
