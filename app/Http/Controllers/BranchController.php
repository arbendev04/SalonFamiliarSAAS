<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreBranchRequest;
use App\Http\Requests\UpdateBranchRequest;
use App\Models\Branch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

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

    public function store(StoreBranchRequest $request): RedirectResponse
    {
        Branch::create($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Sede agregada.']);

        return back();
    }

    public function update(UpdateBranchRequest $request, Branch $branch): RedirectResponse
    {
        $branch->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Sede actualizada.']);

        return back();
    }

    public function destroy(Branch $branch): RedirectResponse
    {
        Gate::authorize('branches.write');

        $branch->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Sede eliminada.']);

        return back();
    }
}
