<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreHolidayRequest;
use App\Http\Requests\UpdateHolidayRequest;
use App\Models\Holiday;
use App\Services\Tenancy\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class HolidayController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('holidays.read');

        $companyId = app(CurrentCompany::class)->id();

        $holidays = Holiday::query()
            ->effectiveForCompany($companyId)
            ->orderBy('date')
            ->get(['id', 'date', 'name', 'company_id'])
            ->map(fn (Holiday $holiday) => [
                'id' => $holiday->id,
                'date' => $holiday->date->format('Y-m-d'),
                'name' => $holiday->name,
                'is_platform_default' => $holiday->company_id === null,
            ])
            ->values();

        return Inertia::render('holidays/Index', [
            'holidays' => $holidays,
            'canManage' => Gate::allows('holidays.write'),
        ]);
    }

    public function store(StoreHolidayRequest $request): RedirectResponse
    {
        Holiday::create([
            ...$request->validated(),
            // Platform-default holidays (company_id = null) are seeder-only
            // (see ColombianHolidaySeeder). This endpoint is company-scoped,
            // so it always writes the active company's id, regardless of
            // what the request contains — 'company_id' is not even in
            // StoreHolidayRequest::rules(), so it can never arrive validated.
            'company_id' => app(CurrentCompany::class)->id(),
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Festivo agregado.']);

        return back();
    }

    public function update(UpdateHolidayRequest $request, Holiday $holiday): RedirectResponse
    {
        $this->abortIfNotOwnedByActiveCompany($holiday);

        $holiday->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Festivo actualizado.']);

        return back();
    }

    public function destroy(Holiday $holiday): RedirectResponse
    {
        Gate::authorize('holidays.write');

        $this->abortIfNotOwnedByActiveCompany($holiday);

        $holiday->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Festivo eliminado.']);

        return back();
    }

    /**
     * Defense-in-depth guard against mutating a platform-default holiday
     * (company_id === null) or another tenant's holiday through this
     * company-scoped endpoint.
     *
     * Route-model binding on {holiday} already resolves through Holiday's
     * default query builder, which keeps BelongsToCompany's global scope
     * active (only HasPlatformOrCompanyDefault::scopeEffectiveForCompany(),
     * called explicitly in index() above, removes it — see
     * app/Models/Concerns/BelongsToCompany.php). That scope runs
     * `where company_id = <active company>`, which never matches
     * company_id IS NULL under SQL's null-comparison semantics, so both a
     * platform-default row and a foreign-company row are already
     * unreachable via {holiday} and binding 404s (ModelNotFoundException)
     * before update()/destroy() ever runs. Verified against
     * tests/Feature/HolidayTest.php's platform-default and cross-tenant
     * cases, mirroring BranchTest::test_a_branch_belonging_to_another_company_cannot_be_updated.
     *
     * This check is kept anyway for the one documented context where the
     * scope does NOT filter at all: BelongsToCompany::bootBelongsToCompany()
     * skips its `where` clause entirely when CurrentCompany::id() is null
     * ("trusted contexts, not user-facing requests" per that trait's own
     * docblock — console commands, seeders). If this controller were ever
     * reached without an active company resolved, binding would stop
     * filtering by company and any row — platform default or another
     * tenant's — could resolve here. The check aborts with 404 rather than
     * 403 so a platform default and an out-of-tenant row stay
     * indistinguishable to the client, matching what route-model-binding
     * already produces for the cross-tenant case.
     */
    private function abortIfNotOwnedByActiveCompany(Holiday $holiday): void
    {
        abort_if($holiday->company_id !== app(CurrentCompany::class)->id(), 404);
    }
}
