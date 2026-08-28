<?php

namespace App\Http\Requests;

use App\Services\Tenancy\CurrentCompany;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePositionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('positions.write');
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
                // Scoped to the current tenant explicitly: the plain
                // "unique" rule would reject a code already used by a
                // different company, even though the (company_id, code)
                // constraint only forbids duplicates within one company.
                Rule::unique('positions', 'code')->where('company_id', app(CurrentCompany::class)->id()),
            ],
            'title' => ['required', 'string', 'max:255'],
            'department' => ['nullable', 'string', 'max:255'],
        ];
    }
}
