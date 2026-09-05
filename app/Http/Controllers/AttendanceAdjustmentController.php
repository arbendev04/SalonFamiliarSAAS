<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAttendanceAdjustmentRequest;
use App\Models\AttendanceAdjustment;
use App\Models\AttendanceEvent;
use App\Models\Employee;
use App\Models\User;
use App\Services\Attendance\AttendanceAdjustmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use RuntimeException;

class AttendanceAdjustmentController extends Controller
{
    public function store(StoreAttendanceAdjustmentRequest $request, Employee $employee, AttendanceAdjustmentService $service): RedirectResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new RuntimeException('No se pudo determinar el usuario autenticado para solicitar el ajuste.');
        }

        $originalEventId = $request->validated('original_event_id');
        $originalEvent = is_string($originalEventId)
            ? AttendanceEvent::query()->find($originalEventId)
            : null;

        // The "before" snapshot is derived from the referenced event itself
        // (never trusted from client input): for type=add there is nothing
        // to snapshot, since the whole point is that the event never
        // existed.
        $originalValue = $originalEvent
            ? [
                'event_type' => $originalEvent->event_type,
                'event_datetime' => $originalEvent->event_datetime->toDateTimeString(),
            ]
            : null;

        $adjustment = $service->create(
            employee: $employee,
            requestedBy: $user,
            type: $request->validated('type'),
            originalEvent: $originalEvent,
            originalValue: $originalValue,
            correctedValue: $request->validated('corrected_value'),
            reason: $request->validated('reason'),
        );

        $message = $adjustment->status === 'approved'
            ? 'Ajuste de asistencia auto-aprobado.'
            : 'Ajuste de asistencia pendiente de aprobación.';

        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }

    public function approve(Request $request, AttendanceAdjustment $adjustment, AttendanceAdjustmentService $service): RedirectResponse
    {
        Gate::authorize('attendance.approve_adjustment');

        $user = $request->user();

        if (! $user instanceof User) {
            throw new RuntimeException('No se pudo determinar el usuario autenticado para aprobar el ajuste.');
        }

        $service->approve($adjustment, $user, $request->input('note'));

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Ajuste de asistencia aprobado.']);

        return back();
    }

    public function reject(Request $request, AttendanceAdjustment $adjustment, AttendanceAdjustmentService $service): RedirectResponse
    {
        Gate::authorize('attendance.approve_adjustment');

        $user = $request->user();

        if (! $user instanceof User) {
            throw new RuntimeException('No se pudo determinar el usuario autenticado para rechazar el ajuste.');
        }

        $service->reject($adjustment, $user, $request->input('note'));

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Ajuste de asistencia rechazado.']);

        return back();
    }
}
