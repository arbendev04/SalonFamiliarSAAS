<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreEmploymentContractRequest;
use App\Models\Employee;
use App\Models\EmploymentContract;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class EmploymentContractController extends Controller
{
    public function store(StoreEmploymentContractRequest $request, Employee $employee): RedirectResponse
    {
        DB::transaction(function () use ($request, $employee) {
            $contract = EmploymentContract::create([
                ...$request->validated(),
                'employee_id' => $employee->id,
                'status' => 'active',
            ]);

            // .ai/04-DOMAIN-MODEL.md: a new contract always starts with its
            // own base salary_history row; later raises append rows here
            // instead of opening a new contract.
            $contract->salaryHistory()->create([
                'company_id' => $contract->company_id,
                'effective_from' => $contract->start_date,
                'base_salary' => $contract->base_salary,
                'reason' => 'contrato inicial',
            ]);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Contrato agregado.']);

        return back();
    }
}
