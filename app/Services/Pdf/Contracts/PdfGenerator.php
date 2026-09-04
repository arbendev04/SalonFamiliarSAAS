<?php

namespace App\Services\Pdf\Contracts;

/**
 * Decouples the PDF module from the concrete rendering library (ADR-011,
 * .ai/14-PDF.md): no other code in this app should call dompdf's classes
 * directly, only this contract.
 */
interface PdfGenerator
{
    /**
     * @param  array<string, mixed>  $data
     * @return string raw PDF bytes
     */
    public function render(string $view, array $data): string;
}
