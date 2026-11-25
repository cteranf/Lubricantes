<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\CommercialOrderPdfService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CommercialOrderPdfController extends Controller
{
    public function customerAvailability(Request $request, Order $order, CommercialOrderPdfService $documents)
    {
        abort_unless((int) $order->user_id === (int) $request->user()->id, 404);

        return response()->json($documents->availability($order), 200, $this->privateHeaders());
    }

    public function customerDownload(Request $request, Order $order, string $kind, CommercialOrderPdfService $documents)
    {
        abort_unless((int) $order->user_id === (int) $request->user()->id, 404);

        return $this->download($order, $kind, $documents);
    }

    public function staffAvailability(Order $order, CommercialOrderPdfService $documents)
    {
        return response()->json($documents->availability($order), 200, $this->privateHeaders());
    }

    public function staffDownload(Order $order, string $kind, CommercialOrderPdfService $documents)
    {
        return $this->download($order, $kind, $documents);
    }

    private function download(Order $order, string $kind, CommercialOrderPdfService $documents): Response
    {
        abort_unless(in_array($kind, ['summary', 'payment-constancy'], true), 404);
        $contents = $kind === 'summary'
            ? $documents->orderSummary($order)
            : $documents->paymentConstancy($order);
        $label = $kind === 'summary' ? 'resumen-pedido' : 'constancia-pago';

        return response($contents, 200, $this->privateHeaders() + [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$label.'-'.$order->id.'.pdf"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function privateHeaders(): array
    {
        return [
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
            'Vary' => 'Authorization, Cookie',
        ];
    }
}
