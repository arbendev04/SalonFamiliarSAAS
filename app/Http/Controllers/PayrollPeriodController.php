<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReopenPayrollPeriodRequest;
use App\Http\Requests\StorePayrollPeriodRequest;
use App\Models\AuditLog;
use App\Models\PayrollConceptDefinition;
use App\Models\PayrollEntry;
use App\Models\PayrollPeriod;
use App\Services\Payroll\PayrollPeriodService;
use App\Services\Tenancy\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class PayrollPeriodController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('payroll.read');

        $companyId = app(CurrentCompany::class)->id();

        $periods = PayrollPeriod::query()
            ->where('company_id', $companyId)
            ->orderByDesc('start_date')
            ->get();

        return Inertia::render('payroll/Index', [
            'periods' => $periods->map(fn (PayrollPeriod $period) => [
                'id' => $period->id,
                'period_type' => $period->period_type,
                'start_date' => $period->start_date->toDateString(),
                'end_date' => $period->end_date->toDateString(),
                'status' => $period->status,
            ]),
            'canCreate' => Gate::allows('payroll.calculate'),
            'canCalculate' => Gate::allows('payroll.calculate'),
        ]);
    }

    public function store(StorePayrollPeriodRequest $request): RedirectResponse
    {
        PayrollPeriod::create([
            'company_id' => app(CurrentCompany::class)->id(),
            'period_type' => $request->validated('period_type'),
            'start_date' => $request->validated('start_date'),
            'end_date' => $request->validated('end_date'),
            'status' => 'open',
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Periodo de nómina creado.']);

        return back();
    }

    public function show(PayrollPeriod $period): Response
    {
        Gate::authorize('payroll.read');

        // BASE_SALARY/OVERTIME concepts are platform defaults
        // (company_id = null, see HasPlatformOrCompanyDefault). The
        // concept() BelongsTo relation still carries PayrollConceptDefinition's
        // BelongsToCompany global scope by default, which excludes
        // company_id IS NULL rows — the same documented bug class that
        // already broke LeaveRecordService in Fase 8 — so it must be
        // dropped explicitly for this eager load, exactly like
        // HasPlatformOrCompanyDefault::scopeEffectiveForCompany() does.
        $entries = $period->entries()
            ->with([
                'employee',
                'lines.concept' => fn ($query) => $query->withoutGlobalScope('company'),
            ])
            ->get();

        $concepts = PayrollConceptDefinition::query()
            ->effectiveForCompany($period->company_id)
            ->get(['id', 'code', 'name']);

        return Inertia::render('payroll/Show', [
            'period' => [
                'id' => $period->id,
                'period_type' => $period->period_type,
                'start_date' => $period->start_date->toDateString(),
                'end_date' => $period->end_date->toDateString(),
                'status' => $period->status,
                'closed_by' => $period->closedBy?->name,
                'closed_at' => $period->closed_at?->toDateTimeString(),
            ],
            'entries' => $entries->map(fn (PayrollEntry $entry) => [
                'id' => $entry->id,
                'employee' => [
                    'id' => $entry->employee->id,
                    'full_name' => $entry->employee->full_name,
                ],
                'contract_id' => $entry->contract_id,
                'status' => $entry->status,
                'gross_total' => $entry->gross_total,
                'deductions_total' => $entry->deductions_total,
                'net_total' => $entry->net_total,
                'lines' => $entry->lines->map(fn ($line) => [
                    'id' => $line->id,
                    'concept' => $line->concept->name,
                    'type' => $line->type,
                    'quantity' => $line->quantity,
                    'rate' => $line->rate,
                    'amount' => $line->amount,
                ]),
            ]),
            'canCalculate' => Gate::allows('payroll.calculate'),
            'canApprove' => Gate::allows('payroll.approve'),
            'canClose' => Gate::allows('payroll.close'),
            'canReopen' => Gate::allows('payroll.reopen'),
            'canAdjust' => Gate::allows('payroll.adjust'),
            'concepts' => $concepts->map(fn (PayrollConceptDefinition $concept) => [
                'id' => $concept->id,
                'code' => $concept->code,
                'name' => $concept->name,
            ]),
        ]);
    }

    /**
     * No try/catch around InvalidPayrollPeriodStatusException: matches
     * OvertimeRecordController/LeaveRecordController's established
     * convention of letting the domain exception propagate to Laravel's
     * default error handler (-> 500) instead of converting it to a
     * flashed error toast.
     */
    public function calculate(Request $request, PayrollPeriod $period, PayrollPeriodService $service): RedirectResponse
    {
        Gate::authorize('payroll.calculate');

        $period = $service->calculate($period, $request->user());

        // PayrollPeriodService::calculate() writes exactly one audit row per
        // call whose newValue carries the ok/blocked summary (see the
        // service's docblock) — reused here rather than re-deriving the
        // summary from PayrollEntry rows a second time.
        $summary = AuditLog::query()
            ->where('entity_type', 'payroll_periods')
            ->where('entity_id', $period->id)
            ->where('action', 'payroll_period.calculated')
            ->latest('created_at')
            ->first()?->new_value ?? [];
        $okCount = $summary['ok_count'] ?? 0;
        $blockedCount = $summary['blocked_count'] ?? 0;

        $message = $blockedCount > 0
            ? "Se calcularon {$okCount} empleados, {$blockedCount} quedaron bloqueados — ver detalle."
            : "Se calcularon {$okCount} empleados.";

        Inertia::flash('toast', [
            'type' => $blockedCount > 0 ? 'warning' : 'success',
            'message' => $message,
        ]);

        return back();
    }

    public function approve(Request $request, PayrollPeriod $period, PayrollPeriodService $service): RedirectResponse
    {
        Gate::authorize('payroll.approve');

        $service->approve($period, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Periodo de nómina aprobado.']);

        return back();
    }

    public function close(Request $request, PayrollPeriod $period, PayrollPeriodService $service): RedirectResponse
    {
        Gate::authorize('payroll.close');

        $service->close($period, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Periodo de nómina cerrado.']);

        return back();
    }

    public function reopen(ReopenPayrollPeriodRequest $request, PayrollPeriod $period, PayrollPeriodService $service): RedirectResponse
    {
        $service->reopen($period, $request->user(), $request->validated('reason'));

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Periodo de nómina reabierto.']);

        return back();
    }
}
