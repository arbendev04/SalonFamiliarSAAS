<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RecalculateAttendanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('time_calculation.calculate');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
        ];
    }
}
