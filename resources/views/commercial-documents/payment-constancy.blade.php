<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Constancia comercial de pagos del pedido #{{ $order->id }}</title>
    <style>
        @page { margin: 72px 42px 48px; }
        body { color: #172033; font: 10px DejaVu Sans, sans-serif; line-height: 1.45; }
        header { border-bottom: 2px solid #1d4ed8; margin-bottom: 15px; padding-bottom: 8px; }
        .brand { color: #1e3a8a; font-size: 16px; font-weight: bold; }
        .muted { color: #64748b; }
        h1 { font-size: 19px; margin: 0 0 12px; }
        h2 { font-size: 12px; margin: 17px 0 7px; color: #1e3a8a; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #eff6ff; text-align: left; }
        th, td { padding: 8px 6px; border-bottom: 1px solid #dbe2ea; vertical-align: top; }
        .right { text-align: right; }
        .summary { width: 65%; margin: 13px 0 0 auto; }
        .summary td { padding: 6px 2px; }
        .total { font-weight: bold; font-size: 12px; border-top: 2px solid #1e3a8a; }
        .status { margin-top: 12px; padding: 9px; border: 1px solid #cbd5e1; background: #f8fafc; }
    </style>
</head>
<body>
    <header>
        <div class="brand">{{ $seller?->commercial_name ?: ($seller?->legal_name ?: 'Vendedor no registrado') }}</div>
        <div class="muted">{{ $seller?->legal_name ?: 'Razón social: No registrada' }} · Identificación: {{ $seller?->tax_id_number ?: 'No registrada' }}</div>
    </header>
    <h1>Constancia comercial de pagos · Pedido #{{ $order->id }}</h1>
    <p><strong>Cliente (cuenta actual):</strong> {{ $order->user?->name ?: 'No registrado' }} · <strong>Fecha del pedido:</strong> {{ $order->created_at?->timezone(config('app.timezone'))->format('d/m/Y H:i') ?: 'No registrada' }}</p>
    <p><strong>Estado:</strong> {{ $paymentProgressLabel }}</p>
    <p>Este documento informa los pagos aprobados y verificables registrados para el pedido. No reemplaza un comprobante tributario.</p>

    <h2>Pagos aprobados</h2>
    <table>
        <thead><tr><th>Fecha de confirmación</th><th>Método</th><th>Referencia</th><th class="right">Importe</th></tr></thead>
        <tbody>
            @foreach($payments as $payment)
                <tr><td>{{ $payment['date'] ?: 'No registrada' }}</td><td>{{ $payment['method'] }}</td><td>{{ $payment['reference'] }}</td><td class="right">{{ $payment['currency'] }} {{ number_format((float) $payment['amount'], 2, '.', ',') }}</td></tr>
                @if($payment['refund_status'])<tr><td colspan="4" class="muted">{{ $payment['refund_status'] }}</td></tr>@endif
            @endforeach
        </tbody>
    </table>

    <table class="summary">
        <tr><td>Importe aprobado verificable</td><td class="right">S/ {{ number_format((float) $approvedAmount, 2, '.', ',') }}</td></tr>
        @if((float) $refundedAmount > 0)<tr><td>Devoluciones completadas</td><td class="right">- S/ {{ number_format((float) $refundedAmount, 2, '.', ',') }}</td></tr>@endif
        <tr class="total"><td>Importe neto recibido</td><td class="right">S/ {{ number_format((float) $netReceivedAmount, 2, '.', ',') }}</td></tr>
        @if(!$orderMarkedRefunded && !$orderCanceled)<tr><td>Saldo pendiente del pedido</td><td class="right">S/ {{ number_format((float) $remainingAmount, 2, '.', ',') }}</td></tr>@endif
    </table>
    @if($orderMarkedRefunded)<p class="status">El estado financiero guardado del pedido figura como reembolsado. Los importes netos se calculan solo con devoluciones completadas vinculadas a pagos verificables.</p>@endif
    @if((float) $refundedAmount > 0)<p class="status">El importe neto refleja las devoluciones completadas registradas. Consulte el historial financiero del pedido para el detalle.</p>@endif
    @if((float) $payments->sum('pending_refund_minor') > 0)<p class="status">Existe una solicitud de devolución en revisión; no se descontó del importe neto hasta que se complete.</p>@endif
</body>
</html>
