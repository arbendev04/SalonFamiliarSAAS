{{--
    Comprobante de nómina — .ai/14-PDF.md "Contenido obligatorio".

    Rendered via App\Services\Pdf\Contracts\PdfGenerator::render() (see
    App\Services\Pdf\DompdfPdfGenerator) and populated by
    App\Services\Pdf\PayrollReceiptService::buildData(). render()'s $data
    array is passed straight into Illuminate\View\View::make(), which
    extracts each top-level key into its OWN view variable — never a single
    `$data` variable — so this template consumes $company/$branch/$employee/
    $period/$lines/$totals/$observations/$version/$generated_at directly,
    matching PayrollReceiptService::buildData()'s exact top-level keys.

    dompdf does not support modern CSS (flexbox/grid), so this layout is
    intentionally table-based with inline-friendly <style> only (width,
    border, padding, text-align, font-family, font-size, font-weight).

    @var array{legal_name: string, tax_id: string} $company
    @var array{name: string}|null $branch
    @var array{full_name: string, document_type: string, national_id: string} $employee
    @var array{start_date: string, end_date: string} $period
    @var list<array{type: string, description: string, quantity: float|null, rate: float|null, amount: float}> $lines
    @var array{gross: float, deductions: float, net: float} $totals
    @var list<array{reason: string, corrected_value: float|null}> $observations
    @var int $version
    @var string $generated_at
--}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Comprobante de nómina</title>
    <style>
        body {
            font-family: Helvetica, Arial, sans-serif;
            font-size: 11px;
            color: #1a1a1a;
        }

        h1 {
            font-size: 16px;
            font-weight: bold;
            margin: 0 0 4px;
        }

        h2 {
            font-size: 12px;
            font-weight: bold;
            margin: 14px 0 4px;
            padding-bottom: 2px;
            border-bottom: 1px solid #999999;
        }

        p {
            margin: 0 0 4px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 6px;
        }

        th, td {
            padding: 4px 6px;
            text-align: left;
            border: 1px solid #cccccc;
        }

        th {
            background-color: #f0f0f0;
            font-weight: bold;
        }

        .text-right {
            text-align: right;
        }

        .totals-table td {
            border: none;
            padding: 2px 6px;
        }

        .totals-label {
            font-weight: bold;
        }

        .muted {
            color: #666666;
        }

        .footer {
            margin-top: 16px;
            font-size: 9px;
            color: #555555;
        }
    </style>
</head>
<body>
    <h1>{{ $company['legal_name'] }}</h1>
    <p>
        NIT: {{ $company['tax_id'] }}
        @if ($branch !== null)
            — Sede: {{ $branch['name'] }}
        @endif
    </p>

    <h2>Trabajador</h2>
    <table>
        <tr>
            <th>Nombre</th>
            <td>{{ $employee['full_name'] }}</td>
            <th>Documento</th>
            <td>{{ $employee['document_type'] }} {{ $employee['national_id'] }}</td>
        </tr>
    </table>

    <h2>Periodo</h2>
    <p>{{ $period['start_date'] }} — {{ $period['end_date'] }}</p>

    <h2>Detalle</h2>
    <table>
        <thead>
        <tr>
            <th>Concepto</th>
            <th class="text-right">Cantidad</th>
            <th class="text-right">Tarifa</th>
            <th class="text-right">Monto</th>
        </tr>
        </thead>
        <tbody>
        @php
            $earningLines = collect($lines)->where('type', 'earning');
            $deductionLines = collect($lines)->where('type', 'deduction');
        @endphp
        @foreach ($earningLines as $line)
            <tr>
                <td>{{ $line['description'] }}</td>
                <td class="text-right">{{ $line['quantity'] !== null ? $line['quantity'] : '—' }}</td>
                <td class="text-right">{{ $line['rate'] !== null ? $line['rate'] : '—' }}</td>
                <td class="text-right">{{ number_format($line['amount'], 2) }}</td>
            </tr>
        @endforeach
        @foreach ($deductionLines as $line)
            <tr>
                <td>{{ $line['description'] }}</td>
                <td class="text-right">{{ $line['quantity'] !== null ? $line['quantity'] : '—' }}</td>
                <td class="text-right">{{ $line['rate'] !== null ? $line['rate'] : '—' }}</td>
                <td class="text-right">{{ number_format($line['amount'], 2) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <h2>Totales</h2>
    <table class="totals-table">
        <tr>
            <td class="totals-label">Devengado</td>
            <td class="text-right">{{ number_format($totals['gross'], 2) }}</td>
        </tr>
        <tr>
            <td class="totals-label">Deducido</td>
            <td class="text-right">{{ number_format($totals['deductions'], 2) }}</td>
        </tr>
        <tr>
            <td class="totals-label">Neto</td>
            <td class="text-right">{{ number_format($totals['net'], 2) }}</td>
        </tr>
    </table>

    <h2>Observaciones</h2>
    @if (count($observations) === 0)
        <p class="muted">Sin observaciones.</p>
    @else
        <table>
            <thead>
            <tr>
                <th>Motivo</th>
                <th class="text-right">Valor corregido</th>
            </tr>
            </thead>
            <tbody>
            @foreach ($observations as $observation)
                <tr>
                    <td>{{ $observation['reason'] }}</td>
                    <td class="text-right">
                        {{ $observation['corrected_value'] !== null ? number_format($observation['corrected_value'], 2) : '—' }}
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif

    <p class="footer">Generado el {{ $generated_at }} — versión {{ $version }}</p>
</body>
</html>
