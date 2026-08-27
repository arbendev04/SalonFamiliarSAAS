<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreWorkScheduleTemplateRequest;
use App\Models\WorkScheduleTemplate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class WorkScheduleTemplateController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('schedules.write');

        return Inertia::render('schedules/Index', [
            'templates' => WorkScheduleTemplate::query()
                ->with('days')
                ->orderBy('name')
                ->get(['id', 'name']),
        ]);
    }

    public function store(StoreWorkScheduleTemplateRequest $request): RedirectResponse
    {
        DB::transaction(function () use ($request) {
            $template = WorkScheduleTemplate::create([
                'name' => $request->validated('name'),
            ]);

            foreach ($request->validated('days') as $day) {
                $template->days()->create([
                    'company_id' => $template->company_id,
                    'day_of_week' => $day['day_of_week'],
                    'start_time' => $day['start_time'],
                    'end_time' => $day['end_time'],
                    'crosses_midnight' => $day['crosses_midnight'] ?? false,
                ]);
            }
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Plantilla de jornada creada.']);

        return back();
    }
}
