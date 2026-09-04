{{--
    Comprobante de nómina — .ai/14-PDF.md "Contenido obligatorio".

    Rendered via App\Services\Pdf\Contracts\PdfGenerator::render() (see
    App\Services\Pdf\DompdfPdfGenerator, commit 046fb20) and populated by
    App\Services\Pdf\PayrollReceiptService (commit 5 of this phase's plan,
    not built yet). That service MUST assemble a $data array matching the
    exact shape documented below.

    dompdf does not support modern CSS (flexbox/grid), so this layout is
    intentionally table-based with inline-friendly <style> only (width,
    border, padding, text-align, font-family, font-size, font-weight).

    @var array{
     company: array{legal_name: string, tax_id: string},
     branch: array{name: string}|null,
     employee: array{full_name: string, document_type: string, national_id: string},
     period: array{start_date: string, end_date: string},
     lines: list<array{type: string, description: string, quantity: float|null, rate: float|null, amount: float}>,
     totals: array{gross: float, deductions: float, net: float},
     observations: list<array{reason: string, corrected_value: float|null}>,
     version: int,
     generated_at: string,
    } $data
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
    <h1>{{ $data['company']['legal_name'] }}</h1>
    <p>
        NIT: {{ $data['company']['tax_id'] }}
        @if ($data['branch'] !== null)
            — Sede: {{ $data['branch']['name'] }}
        @endif
    </p>

    <h2>Trabajador</h2>
    <table>
        <tr>
            <th>Nombre</th>
            <td>{{ $data['employee']['full_name'] }}</td>
            <th>Documento</th>
            <td>{{ $data['employee']['document_type'] }} {{ $data['employee']['national_id'] }}</td>
        </tr>
    </table>

    <h2>Periodo</h2>
    <p>{{ $data['period']['start_date'] }} — {{ $data['period']['end_date'] }}</p>

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
            $earningLines = collect($data['lines'])->where('type', 'earning');
            $deductionLines = collect($data['lines'])->where('type', 'deduction');
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
            <td class="text-right">{{ number_format($data['totals']['gross'], 2) }}</td>
        </tr>
        <tr>
            <td class="totals-label">Deducido</td>
            <td class="text-right">{{ number_format($data['totals']['deductions'], 2) }}</td>
        </tr>
        <tr>
            <td class="totals-label">Neto</td>
            <td class="text-right">{{ number_format($data['totals']['net'], 2) }}</td>
        </tr>
    </table>

    <h2>Observaciones</h2>
    @if (count($data['observations']) === 0)
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
            @foreach ($data['observations'] as $observation)
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

    <p class="footer">Generado el {{ $data['generated_at'] }} — versión {{ $data['version'] }}</p>
</body>
</html>
