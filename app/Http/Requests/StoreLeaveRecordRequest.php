<?php

namespace App\Http\Requests;

use App\Services\Tenancy\CurrentCompany;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLeaveRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('leave.create');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $companyId = app(CurrentCompany::class)->id();

        return [
            'leave_type_id' => [
                'required',
                'uuid',
                // leave_types rows can be a platform default (company_id
                // null) or a per-company override (see
                // HasPlatformOrCompanyDefault). A plain
                // ->where('company_id', $companyId) scope would wrongly
                // reject a valid platform-default id, since SQL's
                // column = value never matches NULL — this mirrors
                // HasPlatformOrCompanyDefault::scopeEffectiveForCompany().
                Rule::exists('leave_types', 'id')->where(
                    fn ($query) => $query->whereNull('company_id')->orWhere('company_id', $companyId)
                ),
            ],
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date', 'after_or_equal:date_from'],
            'reason' => ['required', 'string', 'max:255'],
            'document_ref' => ['nullable', 'string', 'max:255'],
        ];
    }
}
