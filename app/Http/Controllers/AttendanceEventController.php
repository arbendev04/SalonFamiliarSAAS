<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAttendanceEventRequest;
use App\Models\AttendanceEvent;
use App\Models\Employee;
use App\Services\Attendance\AttendanceEventRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class AttendanceEventController extends Controller
{
    public function index(Employee $employee): Response
    {
        Gate::authorize('attendance.read');

        $events = AttendanceEvent::query()
            ->where('employee_id', $employee->id)
            ->orderByDesc('event_datetime')
            ->get();

        return Inertia::render('employees/Attendance', [
            'employee' => $employee->only(['id', 'full_name']),
            'events' => $events->map(fn (AttendanceEvent $event) => [
                'id' => $event->id,
                'event_type' => $event->event_type,
                'event_datetime' => Carbon::parse($event->event_datetime)->toDateTimeString(),
                'source' => $event->source,
                'anomaly' => $event->metadata['anomaly'] ?? null,
            ]),
            'canRecordAttendance' => Gate::allows('attendance.record'),
        ]);
    }

    public function store(StoreAttendanceEventRequest $request, Employee $employee, AttendanceEventRecorder $recorder): RedirectResponse
    {
        $event = $recorder->record(
            employee: $employee,
            eventType: $request->validated('event_type'),
            eventDatetime: Carbon::parse($request->validated('event_datetime')),
            source: $request->validated('source'),
            deviceId: $request->validated('device_id'),
        );

        // wasRecentlyCreated reliably distinguishes the two outcomes here:
        // the recorder either just built $event via AttendanceEvent::create()
        // (wasRecentlyCreated true) or returned one fetched via a plain
        // query when a duplicate matched (wasRecentlyCreated false by
        // construction), and $event is read immediately with no
        // intervening save/refresh that could change that flag.
        $message = $event->wasRecentlyCreated
            ? 'Marcación registrada.'
            : 'Ya existía una marcación equivalente registrada.';

        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }
}
