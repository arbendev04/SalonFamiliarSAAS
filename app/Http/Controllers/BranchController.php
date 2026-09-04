<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreBranchRequest;
use App\Http\Requests\UpdateBranchRequest;
use App\Models\Branch;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class BranchController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('branches.read');

        return Inertia::render('branches/Index', [
            'branches' => Branch::query()
                ->orderBy('name')
                ->get(['id', 'name', 'timezone']),
        ]);
    }

    public function store(StoreBranchRequest $request, AuditLogger $auditLogger): RedirectResponse
    {
        DB::transaction(function () use ($request, $auditLogger) {
            $branch = Branch::create($request->validated());

            $auditLogger->record(
                user: $this->resolveActingUser($request),
                action: 'branch.created',
                entityType: 'branches',
                entityId: $branch->id,
                oldValue: null,
                newValue: $branch->only($branch->getFillable()),
            );
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Sede agregada.']);

        return back();
    }

    public function update(UpdateBranchRequest $request, Branch $branch, AuditLogger $auditLogger): RedirectResponse
    {
        DB::transaction(function () use ($request, $branch, $auditLogger) {
            $oldValue = $branch->only($branch->getFillable());

            $branch->update($request->validated());

            $auditLogger->record(
                user: $this->resolveActingUser($request),
                action: 'branch.updated',
                entityType: 'branches',
                entityId: $branch->id,
                oldValue: $oldValue,
                newValue: $branch->fresh()->only($branch->getFillable()),
            );
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Sede actualizada.']);

        return back();
    }

    public function destroy(Request $request, Branch $branch, AuditLogger $auditLogger): RedirectResponse
    {
        Gate::authorize('branches.write');

        DB::transaction(function () use ($request, $branch, $auditLogger) {
            $oldValue = $branch->only($branch->getFillable());

            $branch->delete();

            $auditLogger->record(
                user: $this->resolveActingUser($request),
                action: 'branch.deleted',
                entityType: 'branches',
                entityId: $branch->id,
                oldValue: $oldValue,
                newValue: null,
            );
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Sede eliminada.']);

        return back();
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
            throw new RuntimeException('No se pudo determinar el usuario autenticado para auditar la operación sobre la sede.');
        }

        return $user;
    }
}
