<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSocialSecurityConceptDefinitionRequest;
use App\Http\Requests\UpdateSocialSecurityConceptDefinitionRequest;
use App\Models\SocialSecurityConceptDefinition;
use App\Services\Tenancy\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class SocialSecurityConceptDefinitionController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('social_security.manage');

        $companyId = app(CurrentCompany::class)->id();

        $concepts = SocialSecurityConceptDefinition::query()
            ->effectiveForCompany($companyId)
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'entity_type', 'company_id'])
            ->map(fn (SocialSecurityConceptDefinition $concept) => [
                'id' => $concept->id,
                'code' => $concept->code,
                'name' => $concept->name,
                'entity_type' => $concept->entity_type,
                'is_platform_default' => $concept->company_id === null,
            ])
            ->values();

        return Inertia::render('social-security/ConceptDefinitions', [
            'concepts' => $concepts,
            'canManage' => Gate::allows('social_security.manage'),
        ]);
    }

    public function store(StoreSocialSecurityConceptDefinitionRequest $request): RedirectResponse
    {
        SocialSecurityConceptDefinition::create([
            ...$request->validated(),
            // Platform-default concepts (company_id = null) are never
            // seeded this phase (see composed-knitting-dusk.md). This
            // endpoint is company-scoped, so it always writes the active
            // company's id, regardless of what the request contains —
            // 'company_id' is not even in
            // StoreSocialSecurityConceptDefinitionRequest::rules(), so it
            // can never arrive validated.
            'company_id' => app(CurrentCompany::class)->id(),
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Concepto agregado.']);

        return back();
    }

    public function update(UpdateSocialSecurityConceptDefinitionRequest $request, SocialSecurityConceptDefinition $concept): RedirectResponse
    {
        $this->abortIfNotOwnedByActiveCompany($concept);

        $concept->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Concepto actualizado.']);

        return back();
    }

    public function destroy(SocialSecurityConceptDefinition $concept): RedirectResponse
    {
        Gate::authorize('social_security.manage');

        $this->abortIfNotOwnedByActiveCompany($concept);

        $concept->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Concepto eliminado.']);

        return back();
    }

    /**
     * Defense-in-depth guard against mutating a platform-default concept
     * (company_id === null) or another tenant's concept through this
     * company-scoped endpoint. Same guard shape as
     * HolidayController::abortIfNotOwnedByActiveCompany() — see that
     * method's docblock and .ai/rules/controllers.md for the full
     * rationale (route-model binding already 404s via BelongsToCompany's
     * global scope; this check only matters when no active company is
     * resolved, which should never happen on a user-facing request).
     */
    private function abortIfNotOwnedByActiveCompany(SocialSecurityConceptDefinition $concept): void
    {
        abort_if($concept->company_id !== app(CurrentCompany::class)->id(), 404);
    }
}
