<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreLeaveRecordRequest;
use App\Models\Employee;
use App\Models\LeaveRecord;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\Leave\LeaveRecordService;
use App\Services\Tenancy\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class LeaveRecordController extends Controller
{
    /**
     * There is no `leave.read` permission in the catalog (.ai
     * 06-AUTHORIZATION.md/PermissionSeeder only define leave.create and
     * leave.approve) — adding one this late in the phase would be scope
     * creep beyond the approved plan. Viewing an employee's leave records is
     * therefore gated on either permission via Gate::any(): anyone who can
     * request a leave OR decide on one can see the list, which covers every
     * seeded role that has one of the two (every role today happens to grant
     * both together, but the check does not assume that).
     */
    public function index(Employee $employee): Response
    {
        if (! Gate::any(['leave.create', 'leave.approve'])) {
            abort(403);
        }

        // leaveType is eager-loaded with its BelongsToCompany global scope
        // explicitly removed: that scope excludes company_id IS NULL rows
        // whenever a company is active, which would silently turn every
        // platform-default leave type (all 4 seeded by
        // EssentialNoveltyCatalogSeeder) into a null relation here — see the
        // matching fix and full explanation in
        // LeaveRecordService::generateNoveltyAndAbsence(). Safe to bypass:
        // leave_type_id is only ever written through
        // StoreLeaveRecordRequest's tenant-aware exists rule, so it can
        // never reference another company's own (non-null) leave type.
        $records = $employee->leaveRecords()
            ->with([
                'leaveType' => fn ($query) => $query->withoutGlobalScope('company'),
                'approvedBy',
            ])
            ->orderByDesc('created_at')
            ->get();

        $companyId = app(CurrentCompany::class)->id();

        $leaveTypes = LeaveType::query()->effectiveForCompany($companyId)->get();

        return Inertia::render('employees/LeaveRecords', [
            'employee' => $employee->only(['id', 'full_name']),
            'records' => $records->map(fn (LeaveRecord $record) => [
                'id' => $record->id,
                'leave_type' => $record->leaveType->name,
                'date_from' => $record->date_from->toDateString(),
                'date_to' => $record->date_to->toDateString(),
                'reason' => $record->reason,
                'status' => $record->status,
                'approved_by' => $record->approvedBy?->name,
            ]),
            'leaveTypes' => $leaveTypes->map(fn (LeaveType $leaveType) => [
                'id' => $leaveType->id,
                'name' => $leaveType->name,
            ])->values(),
            'canCreate' => Gate::allows('leave.create'),
            'canApprove' => Gate::allows('leave.approve'),
        ]);
    }

    public function store(StoreLeaveRecordRequest $request, Employee $employee, LeaveRecordService $service): RedirectResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new RuntimeException('No se pudo determinar el usuario autenticado para solicitar la licencia.');
        }

        $companyId = app(CurrentCompany::class)->id();

        // Re-resolved here (rather than trusted from the FormRequest) the
        // same way StoreAttendanceAdjustmentRequest's original_event_id is
        // only validated, never loaded — the exists rule already proved the
        // id is a visible platform-default-or-own-company row.
        $leaveType = LeaveType::query()
            ->effectiveForCompany($companyId)
            ->findOrFail($request->validated('leave_type_id'));

        $record = $service->create(
            employee: $employee,
            requestedBy: $user,
            leaveType: $leaveType,
            dateFrom: Carbon::parse($request->validated('date_from')),
            dateTo: Carbon::parse($request->validated('date_to')),
            reason: $request->validated('reason'),
            documentRef: $request->validated('document_ref'),
        );

        $message = $record->status === 'approved'
            ? 'Licencia auto-aprobada.'
            : 'Licencia pendiente de aprobación.';

        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }

    public function approve(Request $request, LeaveRecord $record, LeaveRecordService $service): RedirectResponse
    {
        Gate::authorize('leave.approve');

        $user = $request->user();

        if (! $user instanceof User) {
            throw new RuntimeException('No se pudo determinar el usuario autenticado para aprobar la licencia.');
        }

        $service->approve($record, $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Licencia aprobada.']);

        return back();
    }

    public function reject(Request $request, LeaveRecord $record, LeaveRecordService $service): RedirectResponse
    {
        Gate::authorize('leave.approve');

        $user = $request->user();

        if (! $user instanceof User) {
            throw new RuntimeException('No se pudo determinar el usuario autenticado para rechazar la licencia.');
        }

        $service->reject($record, $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Licencia rechazada.']);

        return back();
    }
}
