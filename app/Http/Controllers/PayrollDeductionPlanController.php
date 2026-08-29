<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePayrollDeductionPlanRequest;
use App\Models\Employee;
use App\Models\PayrollConceptDefinition;
use App\Models\PayrollDeductionPlan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class PayrollDeductionPlanController extends Controller
{
    public function index(Employee $employee): Response
    {
        Gate::authorize('payroll.read');

        // LOAN/GARNISHMENT are seeded as platform-default concepts
        // (company_id = null). The concept() relation still carries
        // PayrollConceptDefinition's BelongsToCompany global scope by
        // default, which would exclude those rows — same documented bug
        // class as HasPlatformOrCompanyDefault warns about — so it is
        // dropped explicitly for this eager load.
        $plans = $employee->payrollDeductionPlans()
            ->with(['concept' => fn ($query) => $query->withoutGlobalScope('company')])
            ->get();

        $concepts = PayrollConceptDefinition::query()
            ->effectiveForCompany($employee->company_id)
            ->get(['id', 'code', 'name']);

        return Inertia::render('employees/DeductionPlans', [
            'employee' => $employee->only(['id', 'full_name']),
            'plans' => $plans->map(fn (PayrollDeductionPlan $plan) => [
                'id' => $plan->id,
                'concept' => $plan->concept->name,
                'total_amount' => $plan->total_amount,
                'installments' => $plan->installments,
                'installment_amount' => $plan->installment_amount,
                'remaining' => $plan->remaining,
            ]),
            'concepts' => $concepts->map(fn (PayrollConceptDefinition $concept) => [
                'id' => $concept->id,
                'code' => $concept->code,
                'name' => $concept->name,
            ]),
            'canManage' => Gate::allows('payroll.adjust'),
        ]);
    }

    /**
     * installment_amount = total_amount / installments, computed once here
     * at creation (per commit 1's schema design) so the service never
     * re-derives it every period, which would accumulate rounding drift.
     */
    public function store(StorePayrollDeductionPlanRequest $request, Employee $employee): RedirectResponse
    {
        $totalAmount = (float) $request->validated('total_amount');
        $installments = (int) $request->validated('installments');

        PayrollDeductionPlan::create([
            'company_id' => $employee->company_id,
            'employee_id' => $employee->id,
            'concept_id' => $request->validated('concept_id'),
            'total_amount' => $totalAmount,
            'installments' => $installments,
            'installment_amount' => $totalAmount / $installments,
            'remaining' => $totalAmount,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Plan de deducción creado.']);

        return back();
    }
}
