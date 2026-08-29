<?php

namespace App\Http\Requests;

use App\Services\Tenancy\CurrentCompany;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePayrollDeductionPlanRequest extends FormRequest
{
    /**
     * No dedicated permission exists for deduction plans in the catalog —
     * `payroll.adjust` is the closest semantic match (plan section H).
     */
    public function authorize(): bool
    {
        return $this->user()->can('payroll.adjust');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $companyId = app(CurrentCompany::class)->id();

        return [
            'concept_id' => [
                'required',
                'uuid',
                Rule::exists('payroll_concept_definitions', 'id')->where(
                    fn ($query) => $query->whereNull('company_id')->orWhere('company_id', $companyId)
                ),
            ],
            'total_amount' => ['required', 'numeric', 'min:0.01'],
            'installments' => ['required', 'integer', 'min:1'],
        ];
    }
}
