<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreShiftBreakRequest;
use App\Models\Shift;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class ShiftBreakController extends Controller
{
    public function store(StoreShiftBreakRequest $request, Shift $shift): RedirectResponse
    {
        $shift->breaks()->create([
            'company_id' => $shift->company_id,
            ...$request->validated(),
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Descanso agregado.']);

        return back();
    }
}
