<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePositionRequest;
use App\Http\Requests\UpdatePositionRequest;
use App\Models\Position;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class PositionController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('positions.read');

        return Inertia::render('positions/Index', [
            'positions' => Position::query()
                ->orderBy('title')
                ->get(['id', 'code', 'title', 'department']),
        ]);
    }

    public function store(StorePositionRequest $request, AuditLogger $auditLogger): RedirectResponse
    {
        DB::transaction(function () use ($request, $auditLogger) {
            $position = Position::create($request->validated());

            $auditLogger->record(
                user: $this->resolveActingUser($request),
                action: 'position.created',
                entityType: 'positions',
                entityId: $position->id,
                oldValue: null,
                newValue: $position->only($position->getFillable()),
            );
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Cargo agregado.']);

        return back();
    }

    public function update(UpdatePositionRequest $request, Position $position, AuditLogger $auditLogger): RedirectResponse
    {
        DB::transaction(function () use ($request, $position, $auditLogger) {
            $oldValue = $position->only($position->getFillable());

            $position->update($request->validated());

            $auditLogger->record(
                user: $this->resolveActingUser($request),
                action: 'position.updated',
                entityType: 'positions',
                entityId: $position->id,
                oldValue: $oldValue,
                newValue: $position->fresh()->only($position->getFillable()),
            );
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Cargo actualizado.']);

        return back();
    }

    public function destroy(Request $request, Position $position, AuditLogger $auditLogger): RedirectResponse
    {
        Gate::authorize('positions.write');

        DB::transaction(function () use ($request, $position, $auditLogger) {
            $oldValue = $position->only($position->getFillable());

            $position->delete();

            $auditLogger->record(
                user: $this->resolveActingUser($request),
                action: 'position.deleted',
                entityType: 'positions',
                entityId: $position->id,
                oldValue: $oldValue,
                newValue: null,
            );
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Cargo eliminado.']);

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
            throw new RuntimeException('No se pudo determinar el usuario autenticado para auditar la operación sobre el cargo.');
        }

        return $user;
    }
}
