<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePayrollPeriodRequest extends FormRequest
{
    /**
     * Creating a period is the first step of the calculate workflow
     * (App\Http\Controllers\PayrollPeriodController::store()), so it is
     * gated on the same permission as calculate() rather than a
     * dedicated "create period" permission that does not exist in the
     * catalog.
     */
    public function authorize(): bool
    {
        return $this->user()->can('payroll.calculate');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'period_type' => ['required', Rule::in(['weekly', 'biweekly', 'monthly'])],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after:start_date'],
        ];
    }
}
