<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePayrollInformationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('contracts.write');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'bank_account_enc' => ['required', 'string', 'max:255'],
            'tax_regime' => ['nullable', 'string', 'max:100'],
        ];
    }
}
