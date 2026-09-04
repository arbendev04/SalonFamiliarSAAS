<?php

namespace App\Http\Controllers;

use App\Models\GeneratedDocument;
use App\Models\PayrollEntry;
use App\Services\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class GeneratedDocumentController extends Controller
{
    public function download(PayrollEntry $entry, GeneratedDocument $document): StreamedResponse
    {
        Gate::authorize('payroll.read');

        $this->abortIfNotOwnedByActiveCompany($entry);

        // Cross-reference guard: $document must actually belong to $entry —
        // otherwise a URL swapping in another entry's document id would
        // still resolve (both rows independently pass company-scoped route
        // binding when they belong to the same tenant).
        abort_if(
            $document->reference_entity_type !== 'payroll_entry' || $document->reference_entity_id !== $entry->id,
            404,
        );

        return Storage::download($document->storage_ref, "comprobante-{$entry->id}-v{$document->version}.pdf");
    }

    /**
     * Same defense-in-depth pattern as
     * HolidayController::abortIfNotOwnedByActiveCompany(): route-model
     * binding on {entry} already 404s a foreign-company row via
     * BelongsToCompany's global scope, this guard only covers the
     * documented edge case where CurrentCompany::id() is null and that
     * scope stops filtering entirely.
     */
    private function abortIfNotOwnedByActiveCompany(PayrollEntry $entry): void
    {
        abort_if($entry->company_id !== app(CurrentCompany::class)->id(), 404);
    }
}
