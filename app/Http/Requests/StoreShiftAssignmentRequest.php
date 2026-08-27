<?php

namespace App\Http\Requests;

use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Services\Tenancy\CurrentCompany;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreShiftAssignmentRequest extends FormRequest
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
            'employee_id' => [
                'required',
                'uuid',
                Rule::exists('employees', 'id')->where('company_id', app(CurrentCompany::class)->id()),
            ],
            // Every exceptional shift change is audited (.ai/16-AUDIT.md),
            // and a reason is mandatory for actions that require one.
            'reason' => ['required', 'string', 'max:500'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $shift = $this->route('shift');

            if (! $shift instanceof Shift) {
                return;
            }

            $employeeId = $this->input('employee_id');

            if (! is_string($employeeId)) {
                return;
            }

            $overlaps = ShiftAssignment::overlapsForEmployee(
                $employeeId,
                Carbon::parse($shift->start_datetime)->toDateTimeString(),
                Carbon::parse($shift->end_datetime)->toDateTimeString(),
                $shift->id,
            );

            if ($overlaps) {
                $validator->errors()->add(
                    'employee_id',
                    'Ese empleado ya tiene un turno asignado que se solapa con este horario.',
                );
            }
        });
    }
}
