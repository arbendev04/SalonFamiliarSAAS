<?php

namespace App\Http\Requests;

use App\Models\Employee;
use App\Models\EmploymentContract;
use App\Services\Tenancy\CurrentCompany;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreEmploymentContractRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('contracts.write');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'position_id' => [
                'nullable',
                'uuid',
                // Scoped to the current tenant explicitly, same reasoning
                // as StoreEmployeeRequest::branch_id: the plain "exists"
                // rule would bypass the BelongsToCompany global scope.
                Rule::exists('positions', 'id')->where('company_id', app(CurrentCompany::class)->id()),
            ],
            'contract_type' => ['required', 'string', 'max:50'],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after:start_date'],
            'base_salary' => ['required', 'numeric', 'min:0'],
        ];
    }

    /**
     * Reject a contract that overlaps another already-active contract for
     * the same employee. Postgres also enforces this at the DB level (see
     * the employment_contracts migration), but the sqlite test connection
     * has no equivalent constraint, so .ai/05-DATABASE.md's documented
     * fallback — service-level validation — has to run unconditionally
     * here to actually be enforced everywhere.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $employee = $this->route('employee');

            if (! $employee instanceof Employee) {
                return;
            }

            $start = $this->input('start_date');
            $end = $this->input('end_date');

            $overlaps = EmploymentContract::query()
                ->where('employee_id', $employee->id)
                ->where('start_date', '<=', $end ?? '9999-12-31')
                ->where(function ($query) use ($start) {
                    $query->whereNull('end_date')->orWhere('end_date', '>=', $start);
                })
                ->exists();

            if ($overlaps) {
                $validator->errors()->add(
                    'start_date',
                    'Ya existe un contrato vigente para este empleado que se solapa con estas fechas.',
                );
            }
        });
    }
}
