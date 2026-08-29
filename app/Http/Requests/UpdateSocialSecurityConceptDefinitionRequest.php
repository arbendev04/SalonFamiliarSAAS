<?php

namespace App\Http\Requests;

use App\Services\Tenancy\CurrentCompany;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSocialSecurityConceptDefinitionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('social_security.manage');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => [
                'required',
                'string',
                'max:50',
                // Scoped to the current tenant, and ignoring this record's
                // own row so re-saving a concept without changing its code
                // does not trip the uniqueness check on itself.
                Rule::unique('social_security_concept_definitions', 'code')
                    ->where('company_id', app(CurrentCompany::class)->id())
                    ->ignore($this->route('concept')),
            ],
            'name' => ['required', 'string', 'max:255'],
            'entity_type' => ['required', 'string', 'max:255'],
        ];
    }
}
