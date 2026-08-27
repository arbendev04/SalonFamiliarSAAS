<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreShiftAssignmentRequest;
use App\Models\Shift;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use RuntimeException;

class ShiftAssignmentController extends Controller
{
    public function update(StoreShiftAssignmentRequest $request, Shift $shift, AuditLogger $auditLogger): RedirectResponse
    {
        DB::transaction(function () use ($request, $shift, $auditLogger) {
            $previousAssignment = $shift->assignments()->where('status', 'assigned')->first();

            if ($previousAssignment) {
                $previousAssignment->update(['status' => 'cancelled']);
            }

            $newAssignment = $shift->assignments()->create([
                'company_id' => $shift->company_id,
                'employee_id' => $request->validated('employee_id'),
                'status' => 'assigned',
            ]);

            // Reassigning a shift outside the normal generation cycle is
            // exactly the "cambio excepcional de turno" that
            // .ai/08-SHIFTS.md requires to be audited. Per ADR-018, if the
            // audit write can't happen at all (no resolvable actor), the
            // whole business transaction must abort rather than silently
            // proceed without a trail.
            $user = $request->user();

            if (! $user instanceof User) {
                throw new RuntimeException('No se pudo determinar el usuario autenticado para auditar la reasignación.');
            }

            $auditLogger->record(
                user: $user,
                action: 'shift_assignment.reassigned',
                entityType: 'shifts',
                entityId: $shift->id,
                oldValue: $previousAssignment ? ['employee_id' => $previousAssignment->employee_id] : null,
                newValue: ['employee_id' => $newAssignment->employee_id],
                reason: $request->validated('reason'),
            );
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Turno reasignado.']);

        return back();
    }
}
