<?php

namespace App\Http\Requests;

use App\Services\Tenancy\CurrentCompany;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEmployeeScheduleRequest extends FormRequest
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
            'template_id' => [
                'required',
                'uuid',
                Rule::exists('work_schedule_templates', 'id')->where('company_id', app(CurrentCompany::class)->id()),
            ],
            'effective_from' => ['required', 'date'],
        ];
    }
}
