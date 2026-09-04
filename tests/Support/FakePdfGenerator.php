<?php

namespace Tests\Support;

use App\Services\Pdf\Contracts\PdfGenerator;

/**
 * Test double for App\Services\Pdf\Contracts\PdfGenerator: returns a fixed
 * byte string instead of invoking dompdf, and records the view/data of the
 * most recent render() call so tests can assert on the exact $data array a
 * service assembled — without paying for real PDF rendering in unit tests
 * that only care about the data shape.
 */
class FakePdfGenerator implements PdfGenerator
{
    public ?string $lastView = null;

    /**
     * @var array<string, mixed>|null
     */
    public ?array $lastData = null;

    public function __construct(
        private readonly string $fixedOutput = '%PDF-1.4 fake-payroll-receipt-output',
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function render(string $view, array $data): string
    {
        $this->lastView = $view;
        $this->lastData = $data;

        return $this->fixedOutput;
    }
}
