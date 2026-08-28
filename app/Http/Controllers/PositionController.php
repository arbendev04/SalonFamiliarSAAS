<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePositionRequest;
use App\Http\Requests\UpdatePositionRequest;
use App\Models\Position;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

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

    public function store(StorePositionRequest $request): RedirectResponse
    {
        Position::create($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Cargo agregado.']);

        return back();
    }

    public function update(UpdatePositionRequest $request, Position $position): RedirectResponse
    {
        $position->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Cargo actualizado.']);

        return back();
    }

    public function destroy(Position $position): RedirectResponse
    {
        Gate::authorize('positions.write');

        $position->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Cargo eliminado.']);

        return back();
    }
}
