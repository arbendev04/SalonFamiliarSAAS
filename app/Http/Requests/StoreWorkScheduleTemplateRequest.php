<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreWorkScheduleTemplateRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'days' => ['required', 'array', 'min:1'],
            'days.*.day_of_week' => ['required', 'integer', 'between:0,6'],
            'days.*.start_time' => ['required', 'date_format:H:i'],
            'days.*.end_time' => ['required', 'date_format:H:i'],
            'days.*.crosses_midnight' => ['sometimes', 'boolean'],
        ];
    }
}
