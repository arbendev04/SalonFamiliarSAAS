<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSocialSecurityRuleVersionRequest;
use App\Models\LaborRule;
use App\Models\LaborRuleVersion;
use App\Models\PayrollConceptDefinition;
use App\Models\SocialSecurityConceptDefinition;
use App\Services\Tenancy\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class SocialSecurityRuleVersionController extends Controller
{
    public function index(SocialSecurityConceptDefinition $concept): Response
    {
        Gate::authorize('social_security.manage');

        $this->abortIfNotOwnedByActiveCompany($concept);

        $laborRule = $this->resolveLaborRule($concept);

        $versions = LaborRuleVersion::query()
            ->where('labor_rule_id', $laborRule->id)
            ->with('createdBy:id,name')
            ->orderByDesc('effective_from')
            ->get();

        $payrollConcepts = PayrollConceptDefinition::effectiveCatalog(app(CurrentCompany::class)->id())
            ->map(fn (PayrollConceptDefinition $definition) => [
                'code' => $definition->code,
                'name' => $definition->name,
            ])
            ->values();

        return Inertia::render('social-security/RuleVersions', [
            'concept' => [
                'id' => $concept->id,
                'name' => $concept->name,
            ],
            'laborRuleId' => $laborRule->id,
            'versions' => $versions->map(fn (LaborRuleVersion $version) => [
                'id' => $version->id,
                'effective_from' => $version->effective_from->toDateString(),
                'effective_to' => $version->effective_to?->toDateString(),
                'parameters' => $version->parameters,
                'created_by' => $version->createdBy?->name,
            ]),
            'payrollConcepts' => $payrollConcepts,
            'canManage' => Gate::allows('social_security.manage'),
        ]);
    }

    public function store(StoreSocialSecurityRuleVersionRequest $request, SocialSecurityConceptDefinition $concept): RedirectResponse
    {
        $this->abortIfNotOwnedByActiveCompany($concept);

        $laborRule = $this->resolveLaborRule($concept);

        LaborRuleVersion::create([
            'labor_rule_id' => $laborRule->id,
            'effective_from' => $request->validated('effective_from'),
            'effective_to' => $request->validated('effective_to'),
            'parameters' => $request->validated('parameters'),
            'created_by' => $request->user()->id,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Versión de tasa de aporte creada.']);

        return back();
    }

    /**
     * One LaborRule per social-security concept, `rule_type =
     * 'SOCIAL_SECURITY_' . $concept->code`, per composed-knitting-dusk.md
     * ADR-020 (never a parallel copy of versioned rates — reuse
     * labor_rules/labor_rule_versions). Idempotent: the first visit to
     * either endpoint for a given concept creates the row, every subsequent
     * call reuses it, same pattern as
     * LaborRuleVersionController::index()'s STANDARD_WORKWEEK rule.
     */
    private function resolveLaborRule(SocialSecurityConceptDefinition $concept): LaborRule
    {
        return LaborRule::query()->firstOrCreate(
            ['company_id' => app(CurrentCompany::class)->id(), 'rule_type' => 'SOCIAL_SECURITY_'.$concept->code],
            ['name' => 'Tasa de aporte — '.$concept->name],
        );
    }

    /**
     * Defense-in-depth guard against reading/writing rate versions for a
     * platform-default concept (company_id === null) or another tenant's
     * concept through this company-scoped endpoint. Same guard shape as
     * SocialSecurityConceptDefinitionController::abortIfNotOwnedByActiveCompany()
     * — see that method's docblock and .ai/rules/controllers.md for the full
     * rationale.
     */
    private function abortIfNotOwnedByActiveCompany(SocialSecurityConceptDefinition $concept): void
    {
        abort_if($concept->company_id !== app(CurrentCompany::class)->id(), 404);
    }
}
