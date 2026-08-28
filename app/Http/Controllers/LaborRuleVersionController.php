<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreLaborRuleVersionRequest;
use App\Models\LaborRule;
use App\Models\LaborRuleVersion;
use App\Services\Tenancy\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class LaborRuleVersionController extends Controller
{
    public function index(CurrentCompany $currentCompany): Response
    {
        Gate::authorize('labor_rules.read');

        // Only one rule_type exists per company for now (YAGNI — see the
        // implementation plan), so this avoids needing a separate "pick a
        // labor rule type" UI: the company's single labor rule is created
        // on first visit and reused afterwards.
        $laborRule = LaborRule::query()->firstOrCreate(
            ['company_id' => $currentCompany->id(), 'rule_type' => 'STANDARD_WORKWEEK'],
            ['name' => 'Jornada laboral estándar'],
        );

        $versions = LaborRuleVersion::query()
            ->where('labor_rule_id', $laborRule->id)
            ->with('createdBy:id,name')
            ->orderByDesc('effective_from')
            ->get();

        return Inertia::render('labor-rules/Index', [
            'laborRuleId' => $laborRule->id,
            'versions' => $versions->map(fn (LaborRuleVersion $version) => [
                'id' => $version->id,
                'effective_from' => $version->effective_from->toDateString(),
                'effective_to' => $version->effective_to?->toDateString(),
                'parameters' => $version->parameters,
                'created_by' => $version->createdBy?->name,
            ]),
            'canManage' => Gate::allows('labor_rules.write'),
        ]);
    }

    public function store(StoreLaborRuleVersionRequest $request): RedirectResponse
    {
        LaborRuleVersion::create([
            'labor_rule_id' => $request->validated('labor_rule_id'),
            'effective_from' => $request->validated('effective_from'),
            'effective_to' => $request->validated('effective_to'),
            'parameters' => $request->validated('parameters'),
            'created_by' => $request->user()->id,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Versión de regla laboral creada.']);

        return back();
    }
}
