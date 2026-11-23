<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 108px 34px 48px; }
        body { color: #172033; font-family: DejaVu Sans, sans-serif; font-size: 10px; }
        .notice { border: 2px solid #b45309; color: #92400e; font-size: 15px; font-weight: bold; left: 0; padding: 7px 9px; position: fixed; right: 0; text-align: center; top: -54px; }
        h1 { font-size: 18px; margin: 16px 0 6px; }
        h2 { border-bottom: 1px solid #cbd5e1; font-size: 11px; margin: 16px 0 7px; padding-bottom: 4px; }
        .muted { color: #526178; }
        .grid { width: 100%; }
        .grid td { vertical-align: top; width: 50%; }
        table.lines { border-collapse: collapse; width: 100%; }
        .lines th, .lines td { border-bottom: 1px solid #d8dee8; padding: 6px 4px; text-align: left; }
        .lines th:last-child, .lines td:last-child { text-align: right; }
        .totals { margin-left: auto; margin-top: 12px; width: 45%; }
        .totals td { padding: 4px; }
        .totals td:last-child { text-align: right; }
        .final { border-top: 2px solid #172033; font-size: 13px; font-weight: bold; }
    </style>
</head>
<body>
    <div class="notice">COPIA DE PRUEBA · NO ES UN COMPROBANTE VÁLIDO PARA EFECTOS TRIBUTARIOS</div>
    <h1>{{ $document->document_type === 'boleta' ? 'Boleta de venta electrónica' : 'Factura electrónica' }} · {{ $document->series }}-{{ str_pad((string) $document->document_number, 8, '0', STR_PAD_LEFT) }}</h1>
    <p class="muted">Documento de prueba · Ambiente: QA local · Estado documental: {{ $statusLabel }} · Generado: {{ $document->confirmed_at?->format('d/m/Y H:i') ?? 'No disponible' }}</p>
    <table class="grid"><tr>
        <td><h2>Emisor</h2><strong>{{ $seller['legal_name'] ?? 'No disponible' }}</strong><br>RUC: {{ $seller['tax_id_number'] ?? 'No disponible' }}<br>{{ $seller['fiscal_address'] ?? '' }}</td>
        <td><h2>Receptor</h2><strong>{{ $recipient['name'] ?? 'No disponible' }}</strong><br>{{ ($recipient['document_type'] ?? null) === 'DNI' ? 'DNI' : (($recipient['document_type'] ?? null) === 'RUC' ? 'RUC' : 'Documento') }}: {{ $recipient['document_number'] ?? 'No registrado' }}<br>{{ $recipient['billing_address'] ?? '' }}</td>
    </tr></table>
    <h2>Detalle</h2>
    <table class="lines"><thead><tr><th>Cantidad</th><th>Descripción</th><th>Precio unitario</th><th>Importe</th></tr></thead><tbody>
        @foreach ($items as $item)
            <tr><td>{{ $item['quantity'] ?? 0 }}</td><td>{{ $item['name'] ?? 'Producto no disponible' }}</td><td>S/ {{ $item['unit_price'] ?? '0.00' }}</td><td>S/ {{ $item['subtotal'] ?? '0.00' }}</td></tr>
        @endforeach
    </tbody></table>
    <table class="totals">
        <tr><td>Base gravada</td><td>S/ {{ data_get($tax, 'totals.taxable_base_minor', '0.00') }}</td></tr>
        <tr><td>Base exonerada</td><td>S/ {{ data_get($tax, 'totals.exempt_base_minor', '0.00') }}</td></tr>
        <tr><td>Base inafecta</td><td>S/ {{ data_get($tax, 'totals.unaffected_base_minor', '0.00') }}</td></tr>
        <tr><td>Impuesto total</td><td>S/ {{ data_get($tax, 'totals.tax_minor', '0.00') }}</td></tr>
        <tr class="final"><td>Total</td><td>S/ {{ data_get($amounts, 'total', '0.00') }} {{ $document->currency }}</td></tr>
    </table>
</body>
</html>
