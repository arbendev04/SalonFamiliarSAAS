<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreShiftBreakRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('schedules.write');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'planned_start' => ['required', 'date'],
            'planned_end' => ['required', 'date', 'after:planned_start'],
            'paid' => ['sometimes', 'boolean'],
        ];
    }
}
