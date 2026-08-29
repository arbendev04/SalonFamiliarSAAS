<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePayrollAdjustmentRequest;
use App\Models\PayrollEntry;
use App\Services\Payroll\PayrollAdjustmentService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class PayrollAdjustmentController extends Controller
{
    /**
     * Only the default ADR-026 mechanism (adjustInNextPeriod) is exposed
     * here — the reopen-based mechanism has no dedicated endpoint per the
     * plan, since its mutation goes through PayrollPeriodController::
     * calculate() while the period is 'reopened'.
     *
     * No try/catch: InvalidPayrollPeriodStatusException/
     * NoOpenNextPayrollPeriodException propagate to the default error
     * handler (-> 500), same convention as every other domain exception in
     * this codebase's controllers.
     */
    public function store(StorePayrollAdjustmentRequest $request, PayrollEntry $entry, PayrollAdjustmentService $service): RedirectResponse
    {
        $service->adjustInNextPeriod(
            $entry,
            $request->user(),
            $request->validated('concept_id'),
            (float) $request->validated('amount'),
            $request->validated('type'),
            $request->validated('reason'),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Ajuste registrado en el próximo periodo.']);

        return back();
    }
}
