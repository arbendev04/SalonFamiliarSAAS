<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSocialSecurityConceptDefinitionRequest;
use App\Http\Requests\UpdateSocialSecurityConceptDefinitionRequest;
use App\Models\SocialSecurityConceptDefinition;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Tenancy\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

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

    public function store(StoreSocialSecurityConceptDefinitionRequest $request, AuditLogger $auditLogger): RedirectResponse
    {
        DB::transaction(function () use ($request, $auditLogger) {
            $concept = SocialSecurityConceptDefinition::create([
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

            $auditLogger->record(
                user: $this->resolveActingUser($request),
                action: 'social_security_concept_definition.created',
                entityType: 'social_security_concept_definitions',
                entityId: $concept->id,
                oldValue: null,
                newValue: $concept->only($concept->getFillable()),
            );
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Concepto agregado.']);

        return back();
    }

    public function update(UpdateSocialSecurityConceptDefinitionRequest $request, SocialSecurityConceptDefinition $concept, AuditLogger $auditLogger): RedirectResponse
    {
        $this->abortIfNotOwnedByActiveCompany($concept);

        DB::transaction(function () use ($request, $concept, $auditLogger) {
            $oldValue = $concept->only($concept->getFillable());

            $concept->update($request->validated());

            $auditLogger->record(
                user: $this->resolveActingUser($request),
                action: 'social_security_concept_definition.updated',
                entityType: 'social_security_concept_definitions',
                entityId: $concept->id,
                oldValue: $oldValue,
                newValue: $concept->fresh()->only($concept->getFillable()),
            );
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Concepto actualizado.']);

        return back();
    }

    public function destroy(Request $request, SocialSecurityConceptDefinition $concept, AuditLogger $auditLogger): RedirectResponse
    {
        Gate::authorize('social_security.manage');

        $this->abortIfNotOwnedByActiveCompany($concept);

        DB::transaction(function () use ($request, $concept, $auditLogger) {
            $oldValue = $concept->only($concept->getFillable());

            $concept->delete();

            $auditLogger->record(
                user: $this->resolveActingUser($request),
                action: 'social_security_concept_definition.deleted',
                entityType: 'social_security_concept_definitions',
                entityId: $concept->id,
                oldValue: $oldValue,
                newValue: null,
            );
        });

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

    /**
     * Per ADR-018, if the audit write can't happen at all (no resolvable
     * actor), the whole business transaction must abort rather than
     * silently proceed without a trail.
     */
    private function resolveActingUser(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new RuntimeException('No se pudo determinar el usuario autenticado para auditar la operación sobre el concepto de seguridad social.');
        }

        return $user;
    }
}
