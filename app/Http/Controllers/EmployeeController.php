<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreEmployeeRequest;
use App\Models\Employee;
use App\Models\EmploymentContract;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class EmployeeController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('employees.read');

        return Inertia::render('employees/Index', [
            'employees' => Employee::query()
                ->orderBy('full_name')
                ->get(['id', 'full_name', 'national_id', 'status', 'hire_date']),
        ]);
    }

    public function store(StoreEmployeeRequest $request): RedirectResponse
    {
        Employee::create($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Empleado agregado.']);

        return back();
    }

    public function show(Employee $employee): Response
    {
        Gate::authorize('employees.read');

        $employee->load(['payrollInformation']);

        $contracts = EmploymentContract::query()
            ->where('employee_id', $employee->id)
            ->with('position')
            ->orderByDesc('start_date')
            ->get();

        return Inertia::render('employees/Show', [
            'employee' => $employee->only(['id', 'full_name', 'national_id', 'status', 'hire_date']),
            'contracts' => $contracts->map(fn (EmploymentContract $contract) => [
                'id' => $contract->id,
                'contract_type' => $contract->contract_type,
                'start_date' => Carbon::parse($contract->start_date)->toDateString(),
                'end_date' => $contract->end_date ? Carbon::parse($contract->end_date)->toDateString() : null,
                'base_salary' => (string) $contract->base_salary,
                'status' => $contract->status,
                'position' => $contract->position?->title,
            ]),
            'payrollInformation' => $employee->payrollInformation ? [
                'bank_account_enc' => $employee->payrollInformation->bank_account_enc,
                'tax_regime' => $employee->payrollInformation->tax_regime,
            ] : null,
            'canManageContracts' => Gate::allows('contracts.write'),
        ]);
    }
}
