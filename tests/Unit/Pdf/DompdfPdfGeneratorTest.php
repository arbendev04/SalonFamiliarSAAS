<?php

namespace Tests\Unit\Pdf;

use App\Services\Pdf\Contracts\PdfGenerator;
use Tests\TestCase;

class DompdfPdfGeneratorTest extends TestCase
{
    public function test_render_returns_pdf_bytes_from_the_bound_generator(): void
    {
        $generator = $this->app->make(PdfGenerator::class);

        $bytes = $generator->render('tests.pdf-fixture', ['message' => 'Hello, PDF!']);

        $this->assertNotEmpty($bytes);
        $this->assertStringStartsWith('%PDF-', $bytes);
    }
}
