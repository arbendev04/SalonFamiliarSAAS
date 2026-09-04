<?php

namespace App\Services\Pdf;

use App\Services\Pdf\Contracts\PdfGenerator;
use Barryvdh\DomPDF\PDF as Dompdf;

class DompdfPdfGenerator implements PdfGenerator
{
    public function __construct(
        private readonly Dompdf $pdf,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function render(string $view, array $data): string
    {
        return $this->pdf->loadView($view, $data)->output();
    }
}
