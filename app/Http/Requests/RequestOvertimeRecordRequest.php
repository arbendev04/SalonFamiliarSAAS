<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RequestOvertimeRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('overtime.request');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'requested_minutes' => ['required', 'integer', 'min:1'],
        ];
    }
}
