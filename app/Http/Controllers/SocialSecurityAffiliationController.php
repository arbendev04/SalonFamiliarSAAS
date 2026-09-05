<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSocialSecurityAffiliationRequest;
use App\Models\Employee;
use App\Models\SocialSecurityAffiliation;
use App\Models\SocialSecurityEntity;
use App\Services\SocialSecurity\SocialSecurityAffiliationService;
use App\Services\Tenancy\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator as ValidatorFacade;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class SocialSecurityAffiliationController extends Controller
{
    /**
     * Lists the employee's FULL affiliation history (every row, not only the
     * currently active ones) — HISTORIAL/audit data per
     * composed-knitting-dusk.md's "UI mínima" section — plus which
     * `entity_type`s from the tenant's effective entity catalog still have
     * no active affiliation, so the UI can surface a "not yet affiliated"
     * state instead of treating an empty result as an error.
     */
    public function index(Employee $employee): Response
    {
        Gate::authorize('social_security.manage');

        // SocialSecurityEntity is a DIRECTO/GLOBAL model (nullable
        // company_id, platform-default rows possible via
        // HasPlatformOrCompanyDefault). Eager-loading it bare would silently
        // drop a platform-default entity behind BelongsToCompany's global
        // scope — the same documented bug class called out on
        // SocialSecurityAffiliation::entity()'s docblock, already bitten
        // three times in Fase 8-9.
        $history = $employee->socialSecurityAffiliations()
            ->with(['entity' => fn ($query) => $query->withoutGlobalScope('company')])
            ->orderByDesc('start_date')
            ->get();

        $entities = SocialSecurityEntity::query()
            ->effectiveForCompany($employee->company_id)
            ->orderBy('name')
            ->get(['id', 'type', 'name', 'code']);

        $activeEntityTypes = $history
            ->whereNull('end_date')
            ->pluck('entity_type')
            ->unique();

        $entityTypesWithoutActiveAffiliation = $entities
            ->pluck('type')
            ->unique()
            ->reject(fn (string $type) => $activeEntityTypes->contains($type))
            ->values();

        return Inertia::render('employees/SocialSecurityAffiliations', [
            'employee' => $employee->only(['id', 'full_name']),
            'affiliations' => $history->map(fn (SocialSecurityAffiliation $affiliation) => [
                'id' => $affiliation->id,
                'entity' => $affiliation->entity->name,
                'entity_type' => $affiliation->entity_type,
                'affiliation_number' => $affiliation->affiliation_number,
                'start_date' => $affiliation->start_date->toDateString(),
                'end_date' => $affiliation->end_date?->toDateString(),
                'is_active' => $affiliation->end_date === null,
            ])->values(),
            'entities' => $entities->map(fn (SocialSecurityEntity $entity) => [
                'id' => $entity->id,
                'type' => $entity->type,
                'name' => $entity->name,
                'code' => $entity->code,
            ])->values(),
            'entityTypesWithoutActiveAffiliation' => $entityTypesWithoutActiveAffiliation,
            'canManage' => Gate::allows('social_security.manage'),
        ]);
    }

    /**
     * Decides between SocialSecurityAffiliationService::affiliate() and
     * ::reassign() based on whether an active affiliation already exists for
     * the resolved `entity_type` as of the submitted start_date, resolving
     * `entity_type` independently of
     * StoreSocialSecurityAffiliationRequest::withValidator() — that
     * derivation lives inside a validator closure and never mutates the
     * request payload (confirmed by reading the FormRequest), so this
     * method has to resolve the entity (and its type) again on its own.
     *
     * StoreSocialSecurityAffiliationRequest is NOT type-hinted directly
     * here (which would auto-run its full validation, including
     * withValidator()'s overlap check, before this method body executes at
     * all) because that overlap check is unconditionally violated by a
     * legitimate reassignment: at the moment of validation the currently
     * active affiliation being superseded is still open (its end_date is
     * only set inside SocialSecurityAffiliationService::reassign()'s own
     * transaction), so the check would always reject it. reassign() itself
     * guarantees no overlap can result — it closes the active row the day
     * before the new start date (see its docblock) — so the FormRequest's
     * "at most one active affiliation per entity_type" guard, which exists
     * to protect the *affiliate* path, does not apply to reassignment.
     * rules() (field-level shape/tenancy checks) still runs for both paths;
     * only withValidator()'s overlap closure is conditionally attached.
     */
    public function store(Request $httpRequest, Employee $employee, SocialSecurityAffiliationService $service): RedirectResponse
    {
        Gate::authorize('social_security.manage');

        $formRequest = StoreSocialSecurityAffiliationRequest::createFrom(
            $httpRequest,
            new StoreSocialSecurityAffiliationRequest,
        );

        $current = $this->resolveCurrentAffiliationBeingSuperseded($httpRequest, $employee);

        $validator = ValidatorFacade::make($httpRequest->all(), $formRequest->rules());

        if ($current === null) {
            $formRequest->withValidator($validator);
        }

        $validated = $validator->validate();

        if (! is_string($validated['entity_id'])) {
            throw new RuntimeException('El identificador de la entidad no es válido.');
        }

        $entity = SocialSecurityEntity::query()
            ->effectiveForCompany(app(CurrentCompany::class)->id())
            ->findOrFail($validated['entity_id']);

        $startDate = Carbon::parse($validated['start_date']);
        $affiliationNumber = $validated['affiliation_number'] ?? null;
        $user = $httpRequest->user();

        if ($current === null) {
            $service->affiliate($employee, $entity, $startDate, $affiliationNumber, $user);
        } else {
            $service->reassign($employee, $entity, $startDate, $affiliationNumber, $user);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Afiliación registrada.']);

        return back();
    }

    /**
     * Best-effort peek at the raw, not-yet-validated payload solely to
     * decide affiliate() vs reassign(). A garbage/missing entity_id or
     * start_date here simply falls through to null (the affiliate path),
     * whose subsequent full validation reports the real field error.
     */
    private function resolveCurrentAffiliationBeingSuperseded(Request $httpRequest, Employee $employee): ?SocialSecurityAffiliation
    {
        $entityId = $httpRequest->input('entity_id');
        $startDateInput = $httpRequest->input('start_date');

        if (! is_string($entityId) || ! is_string($startDateInput)) {
            return null;
        }

        $entity = SocialSecurityEntity::query()
            ->effectiveForCompany(app(CurrentCompany::class)->id())
            ->find($entityId);

        if (! $entity instanceof SocialSecurityEntity) {
            return null;
        }

        try {
            $startDate = Carbon::parse($startDateInput);
        } catch (\Exception) {
            return null;
        }

        return SocialSecurityAffiliation::activeFor($employee->id, $entity->type, $startDate);
    }
}
