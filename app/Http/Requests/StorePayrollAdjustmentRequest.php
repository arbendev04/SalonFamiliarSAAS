<?php

namespace App\Http\Requests;

use App\Services\Tenancy\CurrentCompany;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePayrollAdjustmentRequest extends FormRequest
{
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
                // payroll_concept_definitions rows can be a platform
                // default (company_id null) or a per-company override
                // (see HasPlatformOrCompanyDefault) — same tenant-scoped
                // exists() pattern as StoreLeaveRecordRequest::rules()'s
                // leave_type_id.
                Rule::exists('payroll_concept_definitions', 'id')->where(
                    fn ($query) => $query->whereNull('company_id')->orWhere('company_id', $companyId)
                ),
            ],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'type' => ['required', Rule::in(['earning', 'deduction'])],
            'reason' => ['required', 'string'],
        ];
    }
}
