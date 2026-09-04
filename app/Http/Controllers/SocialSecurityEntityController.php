<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSocialSecurityEntityRequest;
use App\Http\Requests\UpdateSocialSecurityEntityRequest;
use App\Models\SocialSecurityEntity;
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

class SocialSecurityEntityController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('social_security.manage');

        $companyId = app(CurrentCompany::class)->id();

        $entities = SocialSecurityEntity::query()
            ->effectiveForCompany($companyId)
            ->orderBy('name')
            ->get(['id', 'type', 'name', 'code', 'company_id'])
            ->map(fn (SocialSecurityEntity $entity) => [
                'id' => $entity->id,
                'type' => $entity->type,
                'name' => $entity->name,
                'code' => $entity->code,
                'is_platform_default' => $entity->company_id === null,
            ])
            ->values();

        return Inertia::render('social-security/Entities', [
            'entities' => $entities,
            'canManage' => Gate::allows('social_security.manage'),
        ]);
    }

    public function store(StoreSocialSecurityEntityRequest $request, AuditLogger $auditLogger): RedirectResponse
    {
        DB::transaction(function () use ($request, $auditLogger) {
            $entity = SocialSecurityEntity::create([
                ...$request->validated(),
                // Platform-default entities (company_id = null) are never
                // seeded this phase (see composed-knitting-dusk.md). This
                // endpoint is company-scoped, so it always writes the active
                // company's id, regardless of what the request contains —
                // 'company_id' is not even in
                // StoreSocialSecurityEntityRequest::rules(), so it can never
                // arrive validated.
                'company_id' => app(CurrentCompany::class)->id(),
            ]);

            $auditLogger->record(
                user: $this->resolveActingUser($request),
                action: 'social_security_entity.created',
                entityType: 'social_security_entities',
                entityId: $entity->id,
                oldValue: null,
                newValue: $entity->only($entity->getFillable()),
            );
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Entidad agregada.']);

        return back();
    }

    public function update(UpdateSocialSecurityEntityRequest $request, SocialSecurityEntity $entity, AuditLogger $auditLogger): RedirectResponse
    {
        $this->abortIfNotOwnedByActiveCompany($entity);

        DB::transaction(function () use ($request, $entity, $auditLogger) {
            $oldValue = $entity->only($entity->getFillable());

            $entity->update($request->validated());

            $auditLogger->record(
                user: $this->resolveActingUser($request),
                action: 'social_security_entity.updated',
                entityType: 'social_security_entities',
                entityId: $entity->id,
                oldValue: $oldValue,
                newValue: $entity->fresh()->only($entity->getFillable()),
            );
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Entidad actualizada.']);

        return back();
    }

    public function destroy(Request $request, SocialSecurityEntity $entity, AuditLogger $auditLogger): RedirectResponse
    {
        Gate::authorize('social_security.manage');

        $this->abortIfNotOwnedByActiveCompany($entity);

        DB::transaction(function () use ($request, $entity, $auditLogger) {
            $oldValue = $entity->only($entity->getFillable());

            $entity->delete();

            $auditLogger->record(
                user: $this->resolveActingUser($request),
                action: 'social_security_entity.deleted',
                entityType: 'social_security_entities',
                entityId: $entity->id,
                oldValue: $oldValue,
                newValue: null,
            );
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Entidad eliminada.']);

        return back();
    }

    /**
     * Defense-in-depth guard against mutating a platform-default entity
     * (company_id === null) or another tenant's entity through this
     * company-scoped endpoint. Same guard shape as
     * HolidayController::abortIfNotOwnedByActiveCompany() — see that
     * method's docblock and .ai/rules/controllers.md for the full
     * rationale (route-model binding already 404s via BelongsToCompany's
     * global scope; this check only matters when no active company is
     * resolved, which should never happen on a user-facing request).
     */
    private function abortIfNotOwnedByActiveCompany(SocialSecurityEntity $entity): void
    {
        abort_if($entity->company_id !== app(CurrentCompany::class)->id(), 404);
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
            throw new RuntimeException('No se pudo determinar el usuario autenticado para auditar la operación sobre la entidad de seguridad social.');
        }

        return $user;
    }
}
