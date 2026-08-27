<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreEmployeeRequest;
use App\Models\Employee;
use Illuminate\Http\RedirectResponse;
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
}
