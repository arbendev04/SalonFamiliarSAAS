<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AuthorizeOvertimeRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('overtime.authorize');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'authorized_minutes' => ['required', 'integer', 'min:1'],
        ];
    }
}
