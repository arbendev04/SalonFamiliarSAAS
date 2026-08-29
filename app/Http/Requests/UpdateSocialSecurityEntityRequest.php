<?php

namespace App\Http\Requests;

use App\Services\Tenancy\CurrentCompany;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSocialSecurityEntityRequest extends FormRequest
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
            'type' => ['required', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'code' => [
                'required',
                'string',
                'max:50',
                // Scoped to the current tenant, and ignoring this record's
                // own row so re-saving an entity without changing its code
                // does not trip the uniqueness check on itself.
                Rule::unique('social_security_entities', 'code')
                    ->where('company_id', app(CurrentCompany::class)->id())
                    ->ignore($this->route('entity')),
            ],
        ];
    }
}
