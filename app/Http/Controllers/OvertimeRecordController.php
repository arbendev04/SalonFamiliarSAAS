<?php

namespace App\Http\Controllers;

use App\Http\Requests\AuthorizeOvertimeRecordRequest;
use App\Http\Requests\RequestOvertimeRecordRequest;
use App\Models\Employee;
use App\Models\OvertimeRecord;
use App\Models\User;
use App\Services\Overtime\OvertimeRecordService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class OvertimeRecordController extends Controller
{
    /**
     * There is no `overtime.read` permission in the catalog (.ai
     * 06-AUTHORIZATION.md/PermissionSeeder only define overtime.request,
     * overtime.authorize and overtime.mark_paid) — adding one this late in
     * the phase would be scope creep beyond the approved plan. Viewing an
     * employee's overtime records is therefore gated on either permission
     * via Gate::any(), mirroring LeaveRecordController::index's identical
     * resolution for the same reason: anyone who can request overtime OR
     * decide on it should be able to see the list.
     */
    public function index(Employee $employee): Response
    {
        if (! Gate::any(['overtime.request', 'overtime.authorize'])) {
            abort(403);
        }

        $records = $employee->overtimeRecords()
            ->with('shift')
            ->orderByDesc('created_at')
            ->get();

        return Inertia::render('employees/OvertimeRecords', [
            'employee' => $employee->only(['id', 'full_name']),
            'records' => $records->map(fn (OvertimeRecord $record) => [
                'id' => $record->id,
                'shift_date' => $record->shift->date->toDateString(),
                'detected_minutes' => $record->detected_minutes,
                'requested_minutes' => $record->requested_minutes,
                'authorized_minutes' => $record->authorized_minutes,
                'status' => $record->status,
            ]),
            'canRequest' => Gate::allows('overtime.request'),
            'canAuthorize' => Gate::allows('overtime.authorize'),
            'canMarkPaid' => Gate::allows('overtime.mark_paid'),
        ]);
    }

    /**
     * detected -> requested. Permission enforcement lives in
     * RequestOvertimeRecordRequest::authorize(), mirroring how
     * StoreLeaveRecordRequest/RecalculateAttendanceRequest gate their own
     * action rather than duplicating a Gate::authorize() call here — this
     * project's established convention whenever an action endpoint needs a
     * validated body field (requested_minutes here), as opposed to the
     * plain Request used for reject()/markPaid() below, which have nothing
     * to validate.
     *
     * No try/catch around InvalidOvertimeRecordStatusException: matches
     * LeaveRecordController's established convention of letting the domain
     * exception propagate to Laravel's default error handler (-> 500)
     * instead of converting it to a flashed error toast. The UI only ever
     * renders this action for a `detected` row, so the exception is not
     * reachable in normal use.
     */
    public function request(RequestOvertimeRecordRequest $request, OvertimeRecord $record, OvertimeRecordService $service): RedirectResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new RuntimeException('No se pudo determinar el usuario autenticado para solicitar la hora extra.');
        }

        $service->request($record, $user, $request->validated('requested_minutes'));

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Hora extra solicitada.']);

        return back();
    }

    /**
     * requested -> authorized. Same FormRequest-owns-authorization
     * convention as request() above.
     */
    public function authorize(AuthorizeOvertimeRecordRequest $request, OvertimeRecord $record, OvertimeRecordService $service): RedirectResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new RuntimeException('No se pudo determinar el usuario autenticado para autorizar la hora extra.');
        }

        $service->authorize($record, $user, $request->validated('authorized_minutes'));

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Hora extra autorizada.']);

        return back();
    }

    /**
     * requested -> rejected. Gated on `overtime.authorize` (not a separate
     * permission): rejecting a request is the negative branch of the same
     * authority as authorizing it, matching how `leave.approve` covers both
     * LeaveRecordController::approve() and ::reject(). No body fields to
     * validate, so a plain Request is used (mirrors
     * AttendanceAdjustmentController::reject/LeaveRecordController::reject).
     */
    public function reject(Request $request, OvertimeRecord $record, OvertimeRecordService $service): RedirectResponse
    {
        Gate::authorize('overtime.authorize');

        $user = $request->user();

        if (! $user instanceof User) {
            throw new RuntimeException('No se pudo determinar el usuario autenticado para rechazar la hora extra.');
        }

        $service->reject($record, $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Hora extra rechazada.']);

        return back();
    }

    /**
     * authorized -> paid. No body fields to validate, so a plain Request is
     * used (see reject() above).
     */
    public function markPaid(Request $request, OvertimeRecord $record, OvertimeRecordService $service): RedirectResponse
    {
        Gate::authorize('overtime.mark_paid');

        $user = $request->user();

        if (! $user instanceof User) {
            throw new RuntimeException('No se pudo determinar el usuario autenticado para marcar como pagada la hora extra.');
        }

        $service->markPaid($record, $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Hora extra marcada como pagada.']);

        return back();
    }
}
