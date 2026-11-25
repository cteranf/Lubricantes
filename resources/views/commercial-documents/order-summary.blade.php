<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Resumen comercial del pedido #{{ $order->id }}</title>
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
        th, td { padding: 7px 6px; border-bottom: 1px solid #dbe2ea; vertical-align: top; }
        .right { text-align: right; }
        .summary { width: 54%; margin: 12px 0 0 auto; }
        .summary td { padding: 5px 2px; }
        .total { font-weight: bold; font-size: 12px; border-top: 2px solid #1e3a8a; }
        .columns { width: 100%; }
        .columns td { width: 50%; border: 0; padding: 4px 10px 4px 0; }
        .breakable { word-wrap: break-word; }
    </style>
</head>
<body>
    <header>
        <div class="brand">{{ $seller?->commercial_name ?: ($seller?->legal_name ?: 'Vendedor no registrado') }}</div>
        <div class="muted">{{ $seller?->legal_name ?: 'Razón social: No registrada' }} · Identificación: {{ $seller?->tax_id_number ?: 'No registrada' }}</div>
    </header>
    <h1>Resumen del pedido #{{ $order->id }}</h1>
    <table class="columns">
        <tr><td><strong>Fecha del pedido:</strong> {{ $order->created_at?->timezone(config('app.timezone'))->format('d/m/Y H:i') ?: 'No registrada' }}</td><td><strong>Vendedor:</strong> {{ $seller?->commercial_name ?: ($seller?->legal_name ?: 'No registrado') }}</td></tr>
        <tr><td><strong>Cliente (cuenta actual):</strong> {{ $customer?->name ?: 'No registrado' }}</td><td><strong>Correo (cuenta actual):</strong> {{ $customer?->email ?: 'No registrado' }}</td></tr>
        <tr><td><strong>Entrega:</strong> {{ $order->delivery_type === 'pickup' ? 'Recojo en sede' : ($order->delivery_type === 'delivery' ? 'Envío a domicilio' : 'No registrada') }}</td><td><strong>Estado de pago:</strong> {{ $paymentStatusLabel }}</td></tr>
    </table>

    <h2>Productos registrados</h2>
    @if($lines->isEmpty())
        <p>No hay productos registrados en este pedido.</p>
    @else
        <table>
            <thead><tr><th>Producto</th><th class="right">Cantidad</th><th class="right">Precio aplicado</th><th class="right">Subtotal</th></tr></thead>
            <tbody>
                @foreach($lines as $line)
                    <tr>
                        <td class="breakable">{{ $line['name'] }}@if($line['name_is_current_fallback'])<div class="muted">Nombre actual del catálogo; el nombre histórico no estaba registrado.</div>@endif</td>
                        <td class="right">{{ $line['quantity'] }}</td>
                        <td class="right">
                            @if((float) $line['line_savings'] > 0)
                                <span class="muted" style="text-decoration:line-through">S/ {{ number_format((float) $line['regular_unit_price'], 2, '.', ',') }}</span><br>
                            @endif
                            S/ {{ number_format((float) $line['unit_price'], 2, '.', ',') }}
                            @if((float) $line['line_savings'] > 0)
                                <div class="muted">Ahorro de la línea: S/ {{ number_format((float) $line['line_savings'], 2, '.', ',') }}</div>
                            @endif
                        </td>
                        <td class="right">S/ {{ number_format((float) $line['line_subtotal'], 2, '.', ',') }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <table class="summary">
        <tr><td>Subtotal de productos</td><td class="right">S/ {{ number_format((float) ($order->subtotal ?? $lines->sum(fn ($line) => (float) $line['line_subtotal'])), 2, '.', ',') }}</td></tr>
        @if((float) $order->discount_total > 0)<tr><td>Descuentos comerciales registrados</td><td class="right">− S/ {{ number_format((float) $order->discount_total, 2, '.', ',') }}</td></tr>@endif
        <tr><td>Costo de envío</td><td class="right">S/ {{ number_format((float) ($order->shipping_amount ?? 0), 2, '.', ',') }}</td></tr>
        <tr class="total"><td>Total registrado</td><td class="right">S/ {{ number_format((float) $order->total, 2, '.', ',') }}</td></tr>
    </table>
    <p class="muted">Importes presentados según los datos guardados en el pedido; no se recalculan desde los precios actuales del catálogo. La cuenta y el perfil del vendedor se muestran con sus datos disponibles al momento de la descarga; este pedido no guarda snapshots de esos datos.</p>
</body>
</html>
