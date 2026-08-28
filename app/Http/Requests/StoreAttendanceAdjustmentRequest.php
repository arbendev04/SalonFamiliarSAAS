<?php

namespace App\Http\Requests;

use App\Models\Employee;
use App\Services\Tenancy\CurrentCompany;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreAttendanceAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('attendance.adjust');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $employee = $this->route('employee');
        $employeeId = $employee instanceof Employee ? $employee->id : null;

        return [
            'type' => ['required', 'string', Rule::in(['modify', 'add', 'invalidate'])],
            // A missing/wrong marking is a sensitive correction; per
            // .ai/07-ATTENDANCE.md an adjustment without a written reason
            // is invalid and must be rejected before it is ever persisted.
            'reason' => ['required', 'string', 'max:500'],
            'original_event_id' => [
                Rule::requiredIf(fn () => in_array($this->input('type'), ['modify', 'invalidate'], true)),
                Rule::prohibitedIf(fn () => $this->input('type') === 'add'),
                'nullable',
                'uuid',
                Rule::exists('attendance_events', 'id')
                    ->where('company_id', app(CurrentCompany::class)->id())
                    ->where('employee_id', $employeeId),
            ],
            'corrected_value' => ['required', 'array'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($this->input('type') !== 'add') {
                return;
            }

            // For type=add, corrected_value stands in for the missing
            // AttendanceEvent, so it must carry the same two fields
            // AttendanceEventRecorder::record() needs to create one.
            if (! is_string($this->input('corrected_value.event_type')) || $this->input('corrected_value.event_type') === '') {
                $validator->errors()->add('corrected_value.event_type', 'El tipo de marcación es obligatorio para agregar un evento.');
            } elseif (! in_array($this->input('corrected_value.event_type'), ['clock_in', 'break_start', 'break_end', 'clock_out'], true)) {
                $validator->errors()->add('corrected_value.event_type', 'El tipo de marcación no es válido.');
            }

            if (! is_string($this->input('corrected_value.event_datetime')) || strtotime((string) $this->input('corrected_value.event_datetime')) === false) {
                $validator->errors()->add('corrected_value.event_datetime', 'La fecha y hora del evento a agregar es obligatoria.');
            }
        });
    }
}
