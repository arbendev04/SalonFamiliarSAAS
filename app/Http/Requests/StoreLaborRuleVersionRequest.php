<?php

namespace App\Http\Requests;

use App\Models\LaborRuleVersion;
use App\Services\Tenancy\CurrentCompany;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreLaborRuleVersionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('labor_rules.write');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'labor_rule_id' => [
                'required',
                'uuid',
                // Scoped to the current tenant explicitly, same reasoning as
                // StoreEmploymentContractRequest::position_id: the plain
                // "exists" rule would bypass the BelongsToCompany global scope.
                Rule::exists('labor_rules', 'id')->where('company_id', app(CurrentCompany::class)->id()),
            ],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after:effective_from'],
            'parameters.tolerance_minutes' => ['required', 'integer', 'min:0'],
            'parameters.rounding_minutes' => ['required', 'integer', 'min:1'],
        ];
    }

    /**
     * Reject a version that overlaps another already-active version for the
     * same labor rule. Postgres also enforces this at the DB level (see the
     * labor_rule_versions migration), but the sqlite test connection has no
     * equivalent constraint, so .ai/05-DATABASE.md's documented fallback —
     * service-level validation — has to run unconditionally here to
     * actually be enforced everywhere.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $laborRuleId = $this->input('labor_rule_id');

            if (! is_string($laborRuleId) || $laborRuleId === '') {
                return;
            }

            $start = $this->input('effective_from');
            $end = $this->input('effective_to');

            $overlaps = LaborRuleVersion::query()
                ->where('labor_rule_id', $laborRuleId)
                ->where('effective_from', '<=', $end ?? '9999-12-31')
                ->where(function ($query) use ($start) {
                    $query->whereNull('effective_to')->orWhere('effective_to', '>=', $start);
                })
                ->exists();

            if ($overlaps) {
                $validator->errors()->add(
                    'effective_from',
                    'Ya existe una versión vigente de esta regla laboral que se solapa con estas fechas.',
                );
            }
        });
    }
}
