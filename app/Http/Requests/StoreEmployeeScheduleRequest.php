<?php

namespace App\Http\Requests;

use App\Models\Employee;
use App\Models\EmployeeSchedule;
use App\Services\Tenancy\CurrentCompany;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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

    /**
     * Reject a new assignment whose effective_from is on or after an
     * already-existing EmployeeSchedule's effective_from. The controller's
     * "close previous" logic (EmployeeScheduleController::store()) only
     * closes a schedule that was active the day before the new
     * effective_from, so it can never find or close a schedule that starts
     * on-or-after the new one — leaving two "active" rows for the same
     * employee and date, which later trips
     * EmployeeSchedule::activeForEmployeeAt()'s AmbiguousScheduleException.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $employee = $this->route('employee');

            if (! $employee instanceof Employee) {
                return;
            }

            $effectiveFrom = $this->input('effective_from');

            $hasLaterOrSameStartingSchedule = EmployeeSchedule::query()
                ->where('employee_id', $employee->id)
                ->where('effective_from', '>=', $effectiveFrom)
                ->exists();

            if ($hasLaterOrSameStartingSchedule) {
                $validator->errors()->add(
                    'effective_from',
                    'Ya existe una jornada asignada para este empleado que comienza en la misma fecha o después.',
                );
            }
        });
    }
}
