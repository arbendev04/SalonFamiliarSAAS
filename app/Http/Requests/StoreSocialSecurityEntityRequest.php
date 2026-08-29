<?php

namespace App\Http\Requests;

use App\Services\Tenancy\CurrentCompany;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSocialSecurityEntityRequest extends FormRequest
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
                // Scoped to the current tenant explicitly: the plain
                // "unique" rule would reject a code already used by a
                // different company, even though the (company_id, code)
                // constraint only forbids duplicates within one company.
                Rule::unique('social_security_entities', 'code')->where('company_id', app(CurrentCompany::class)->id()),
            ],
        ];
    }
}
