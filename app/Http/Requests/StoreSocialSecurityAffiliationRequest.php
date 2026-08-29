<?php

namespace App\Http\Requests;

use App\Models\SocialSecurityAffiliation;
use App\Models\SocialSecurityEntity;
use App\Services\Tenancy\CurrentCompany;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreSocialSecurityAffiliationRequest extends FormRequest
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
            'employee_id' => [
                'required',
                'uuid',
                // Scoped to the current tenant explicitly, same reasoning as
                // StoreShiftAssignmentRequest::employee_id: the plain
                // "exists" rule would bypass the BelongsToCompany global
                // scope.
                Rule::exists('employees', 'id')->where('company_id', $companyId),
            ],
            'entity_id' => [
                'required',
                'uuid',
                // Scoped to the tenant's effective entity catalog (platform
                // defaults plus this company's own overrides), same shape as
                // StoreSocialSecurityRuleVersionRequest::base_concept_codes
                // and StorePayrollDeductionPlanRequest::concept_id — a plain
                // "exists" rule would exclude every platform-default entity
                // (company_id IS NULL never matches a plain equality check).
                // Equivalent to SocialSecurityEntity::effectiveForCompany().
                Rule::exists('social_security_entities', 'id')->where(
                    fn ($query) => $query->whereNull('company_id')->orWhere('company_id', $companyId)
                ),
            ],
            'affiliation_number' => ['nullable', 'string', 'max:255'],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after:start_date'],
        ];
    }

    /**
     * Reject a new affiliation that overlaps another already-active
     * affiliation of the same `entity_type` for the same employee — "at
     * most one active affiliation per entity_type" (composed-knitting-dusk.md
     * decision 2), keyed on entity_type rather than the exact entity_id, so
     * two entities of different types never conflict with each other.
     * Postgres also enforces this at the DB level (see the
     * social_security_affiliations migration), but the sqlite test
     * connection has no equivalent constraint, so .ai/05-DATABASE.md's
     * documented fallback — service-level validation — has to run
     * unconditionally here to actually be enforced everywhere.
     *
     * entity_type is never trusted from client input — it is not even a
     * validated field above — it is always resolved server-side from the
     * given entity_id, exactly as composed-knitting-dusk.md's decision 2
     * requires ("poblado por el servicio desde la entidad, nunca escrito
     * directo por el usuario"). This FormRequest only ever creates a new
     * affiliation row: SocialSecurityAffiliationService::affiliate() and
     * ::reassign() (a later commit) both insert, closing any predecessor row
     * as a separate write instead of updating it in place, so no
     * self-exclusion is needed here — the check simply asks whether any
     * other row already covers the requested range.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $employeeId = $this->input('employee_id');
            $entityId = $this->input('entity_id');

            if (! is_string($employeeId) || ! is_string($entityId)) {
                return;
            }

            $entity = SocialSecurityEntity::query()
                ->effectiveForCompany(app(CurrentCompany::class)->id())
                ->find($entityId);

            if (! $entity instanceof SocialSecurityEntity) {
                return;
            }

            $start = $this->input('start_date');
            $end = $this->input('end_date');

            $overlaps = SocialSecurityAffiliation::query()
                ->where('employee_id', $employeeId)
                ->where('entity_type', $entity->type)
                ->where('start_date', '<=', $end ?? '9999-12-31')
                ->where(function ($query) use ($start) {
                    $query->whereNull('end_date')->orWhere('end_date', '>=', $start);
                })
                ->exists();

            if ($overlaps) {
                $validator->errors()->add(
                    'start_date',
                    'Ya existe una afiliación vigente de este tipo para este empleado que se solapa con estas fechas.',
                );
            }
        });
    }
}
