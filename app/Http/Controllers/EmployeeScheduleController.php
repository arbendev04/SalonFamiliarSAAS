<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreEmployeeScheduleRequest;
use App\Models\Employee;
use App\Models\EmployeeSchedule;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use RuntimeException;

class EmployeeScheduleController extends Controller
{
    public function store(StoreEmployeeScheduleRequest $request, Employee $employee, AuditLogger $auditLogger): RedirectResponse
    {
        DB::transaction(function () use ($request, $employee, $auditLogger) {
            $effectiveFrom = Carbon::parse($request->validated('effective_from'));
            $dayBefore = $effectiveFrom->copy()->subDay();

            // .ai/08-SHIFTS.md: assigning a new template closes the
            // previous assignment instead of overwriting it.
            $current = $employee->activeScheduleAt($dayBefore);
            $oldValue = $current ? $this->fillableSnapshot($current) : null;

            if ($current) {
                $current->update(['effective_to' => $dayBefore->toDateString()]);
            }

            $newSchedule = EmployeeSchedule::create([
                'employee_id' => $employee->id,
                'template_id' => $request->validated('template_id'),
                'effective_from' => $effectiveFrom->toDateString(),
            ]);

            $auditLogger->record(
                user: $this->resolveActingUser($request),
                action: 'employee_schedule.assigned',
                entityType: 'employee_schedules',
                entityId: $newSchedule->id,
                oldValue: $oldValue,
                newValue: $this->fillableSnapshot($newSchedule),
            );
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Jornada asignada.']);

        return back();
    }

    /**
     * Fillable-attribute snapshot for the audit trail, taken via toArray()
     * rather than only() so the 'date:Y-m-d' casts on effective_from/
     * effective_to serialize to plain date strings instead of leaking raw
     * Carbon instances (which only() -> getAttribute() would return) into
     * the JSON old_value/new_value columns.
     *
     * @return array<string, mixed>
     */
    private function fillableSnapshot(EmployeeSchedule $schedule): array
    {
        return array_intersect_key($schedule->toArray(), array_flip($schedule->getFillable()));
    }

    /**
     * Per ADR-018, if the audit write can't happen at all (no resolvable
     * actor), the whole business transaction must abort rather than
     * silently proceed without a trail.
     */
    private function resolveActingUser(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new RuntimeException('No se pudo determinar el usuario autenticado para auditar la asignación de jornada.');
        }

        return $user;
    }
}
