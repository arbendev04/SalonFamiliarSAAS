<?php

namespace App\Http\Requests;

use App\Models\Employee;
use App\Models\ShiftAssignment;
use App\Services\Tenancy\CurrentCompany;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreShiftRequest extends FormRequest
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
            'branch_id' => [
                'nullable',
                'uuid',
                Rule::exists('branches', 'id')->where('company_id', app(CurrentCompany::class)->id()),
            ],
            'date' => ['required', 'date'],
            'start_datetime' => ['required', 'date'],
            'end_datetime' => ['required', 'date', 'after:start_datetime'],
            'type' => ['sometimes', 'string', 'max:50'],
            'crosses_midnight' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Manual shifts (double shifts, exceptional coverage) go through the
     * same explicit-rejection rule as the generator's silent skip — here
     * it must be a hard, visible validation error instead, per
     * .ai/08-SHIFTS.md's "Errores" section.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $employee = $this->route('employee');

            if (! $employee instanceof Employee) {
                return;
            }

            $overlaps = ShiftAssignment::overlapsForEmployee(
                $employee->id,
                (string) $this->input('start_datetime'),
                (string) $this->input('end_datetime'),
            );

            if ($overlaps) {
                $validator->errors()->add(
                    'start_datetime',
                    'El empleado ya tiene un turno asignado que se solapa con este horario.',
                );
            }
        });
    }
}
