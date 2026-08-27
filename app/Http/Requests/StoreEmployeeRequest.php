<?php

namespace App\Http\Requests;

use App\Services\Tenancy\CurrentCompany;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('employees.create');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'full_name' => ['required', 'string', 'max:255'],
            'document_type' => ['required', 'string', 'max:10'],
            'national_id' => ['required', 'string', 'max:50'],
            'birth_date' => ['nullable', 'date'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:255'],
            'hire_date' => ['required', 'date'],
            'branch_id' => [
                'nullable',
                'uuid',
                // Scoped to the current tenant explicitly: the plain
                // "exists" rule queries the table directly and would not
                // respect the BelongsToCompany global scope, which would
                // let a request reference another company's branch.
                Rule::exists('branches', 'id')->where('company_id', app(CurrentCompany::class)->id()),
            ],
        ];
    }
}
