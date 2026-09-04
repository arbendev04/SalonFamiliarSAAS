<?php

namespace App\Services\Pdf;

use App\Exceptions\InvalidPayrollPeriodStatusException;
use App\Exceptions\MissingRequiredReceiptDataException;
use App\Models\GeneratedDocument;
use App\Models\PayrollEntry;
use App\Models\PayrollEntryLine;
use App\Models\User;
use App\Services\Pdf\Contracts\PdfGenerator;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * Materializes a payroll_entry's already-calculated settlement into an
 * immutable PDF receipt (.ai/14-PDF.md) — this service never calculates
 * anything itself, it only renders and stores what Payroll (Fase 9) and
 * Social Security (Fase 10) already produced.
 */
class PayrollReceiptService
{
    public function __construct(
        private readonly PdfGenerator $pdfGenerator,
    ) {}

    /**
     * @throws InvalidPayrollPeriodStatusException if $entry's period is not 'closed'
     * @throws MissingRequiredReceiptDataException if $entry has no payroll_entry_lines
     */
    public function generate(PayrollEntry $entry, User $generatedBy): GeneratedDocument
    {
        $period = $entry->payrollPeriod;

        if ($period->status !== 'closed') {
            throw new InvalidPayrollPeriodStatusException($period->id, $period->status, 'closed');
        }

        // Known bug (documented in .ai/26-PROGRESS.md, already bitten this
        // codebase 3 times): PayrollConceptDefinition is a DIRECTO/GLOBAL
        // catalog (nullable company_id), so BelongsToCompany's global scope
        // excludes a platform-default (company_id = null) concept even
        // through the lines.concept belongsTo() relation unless it is
        // explicitly dropped here.
        $entry->load([
            'employee.branch',
            'company',
            'lines.concept' => function (BelongsTo $query) {
                $query->withoutGlobalScope('company');
            },
            'payrollAdjustments',
            'payrollPeriod',
        ]);

        if ($entry->lines->isEmpty()) {
            throw new MissingRequiredReceiptDataException(
                $entry->id,
                'no tiene líneas de nómina calculadas (payroll_entry_lines vacío).',
            );
        }

        $nextVersion = (int) (GeneratedDocument::query()
            ->where('reference_entity_type', 'payroll_entry')
            ->where('reference_entity_id', $entry->id)
            ->max('version')) + 1;

        $data = $this->buildData($entry, $nextVersion);

        $pdfBytes = $this->pdfGenerator->render('pdf.payroll-receipt', $data);

        $storageRef = "receipts/{$entry->company_id}/{$entry->employee_id}/{$entry->id}/v{$nextVersion}.pdf";

        Storage::put($storageRef, $pdfBytes);

        return GeneratedDocument::create([
            'company_id' => $entry->company_id,
            'type' => 'payroll_receipt',
            'reference_entity_type' => 'payroll_entry',
            'reference_entity_id' => $entry->id,
            'storage_ref' => $storageRef,
            'generated_by' => $generatedBy->id,
            'version' => $nextVersion,
        ]);
    }

    /**
     * Assembles the $data array in the exact shape documented by
     * resources/views/pdf/payroll-receipt.blade.php's docblock. The template
     * itself groups lines by type (earning before deduction), so raw
     * $entry->lines order is passed through unchanged.
     *
     * @return array<string, mixed>
     */
    private function buildData(PayrollEntry $entry, int $version): array
    {
        $employee = $entry->employee;
        $branch = $employee->branch;
        $period = $entry->payrollPeriod;

        return [
            'company' => [
                'legal_name' => $entry->company->legal_name,
                'tax_id' => $entry->company->tax_id,
            ],
            'branch' => $branch !== null ? ['name' => $branch->name] : null,
            'employee' => [
                'full_name' => $employee->full_name,
                'document_type' => $employee->document_type,
                'national_id' => $employee->national_id,
            ],
            'period' => [
                'start_date' => $period->start_date->format('Y-m-d'),
                'end_date' => $period->end_date->format('Y-m-d'),
            ],
            'lines' => $entry->lines
                ->map(fn (PayrollEntryLine $line): array => [
                    'type' => $line->type,
                    'description' => $line->concept->name,
                    'quantity' => $line->quantity !== null ? (float) $line->quantity : null,
                    'rate' => $line->rate !== null ? (float) $line->rate : null,
                    'amount' => (float) $line->amount,
                ])
                ->all(),
            'totals' => [
                'gross' => (float) $entry->gross_total,
                'deductions' => (float) $entry->deductions_total,
                'net' => (float) $entry->net_total,
            ],
            'observations' => $entry->payrollAdjustments
                ->map(fn ($adjustment): array => [
                    'reason' => $adjustment->reason,
                    'corrected_value' => $adjustment->corrected_value['amount'] ?? null,
                ])
                ->all(),
            'version' => $version,
            'generated_at' => now()->toDateTimeString(),
        ];
    }
}
