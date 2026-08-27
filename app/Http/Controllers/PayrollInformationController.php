<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePayrollInformationRequest;
use App\Models\Employee;
use App\Models\PayrollInformation;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class PayrollInformationController extends Controller
{
    public function store(StorePayrollInformationRequest $request, Employee $employee): RedirectResponse
    {
        PayrollInformation::updateOrCreate(
            ['employee_id' => $employee->id],
            $request->validated(),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Datos de pago actualizados.']);

        return back();
    }
}
