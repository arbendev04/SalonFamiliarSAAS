<?php

namespace App\Http\Requests;

use App\Models\LaborRule;
use App\Models\LaborRuleVersion;
use App\Models\SocialSecurityConceptDefinition;
use App\Services\Tenancy\CurrentCompany;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreSocialSecurityRuleVersionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('social_security.manage');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $companyId = app(CurrentCompany::class)->id();

        return [
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after:effective_from'],
            'parameters.employee_pct' => ['required', 'numeric', 'min:0', 'max:1'],
            'parameters.employer_pct' => ['required', 'numeric', 'min:0', 'max:1'],
            'parameters.base_concept_codes' => ['required', 'array', 'min:1'],
            // Scoped to the tenant's effective payroll concept catalog
            // (platform defaults plus this company's own overrides), same
            // shape as StorePayrollDeductionPlanRequest::concept_id — a
            // plain "exists" rule would exclude every platform-default
            // concept (company_id IS NULL never matches a plain equality
            // check).
            'parameters.base_concept_codes.*' => [
                'string',
                Rule::exists('payroll_concept_definitions', 'code')->where(
                    fn ($query) => $query->whereNull('company_id')->orWhere('company_id', $companyId)
                ),
            ],
        ];
    }

    /**
     * Reject a version that overlaps another already-active version for the
     * same underlying labor rule. Mirrors
     * StoreLaborRuleVersionRequest::withValidator() exactly, except the
     * labor_rule_id is never user input here — it is derived from the
     * {concept} route-model-bound SocialSecurityConceptDefinition, the same
     * resolution the controller performs. Postgres also enforces this at the
     * DB level (see the labor_rule_versions migration), but the sqlite test
     * connection has no equivalent constraint, so this fallback has to run
     * unconditionally to actually be enforced everywhere.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $concept = $this->route('concept');

            if (! $concept instanceof SocialSecurityConceptDefinition) {
                return;
            }

            $laborRule = LaborRule::query()
                ->where('company_id', app(CurrentCompany::class)->id())
                ->where('rule_type', 'SOCIAL_SECURITY_'.$concept->code)
                ->first();

            // No labor rule created yet for this concept: nothing to
            // overlap with.
            if ($laborRule === null) {
                return;
            }

            $start = $this->input('effective_from');
            $end = $this->input('effective_to');

            $overlaps = LaborRuleVersion::query()
                ->where('labor_rule_id', $laborRule->id)
                ->where('effective_from', '<=', $end ?? '9999-12-31')
                ->where(function ($query) use ($start) {
                    $query->whereNull('effective_to')->orWhere('effective_to', '>=', $start);
                })
                ->exists();

            if ($overlaps) {
                $validator->errors()->add(
                    'effective_from',
                    'Ya existe una versión vigente de esta tasa de aporte que se solapa con estas fechas.',
                );
            }
        });
    }
}
