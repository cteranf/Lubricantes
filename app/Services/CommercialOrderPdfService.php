<?php

namespace App\Services;

use App\Models\BusinessProfile;
use App\Models\Order;
use App\Models\PaymentTransaction;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CommercialOrderPdfService
{
    public const NOTICE = 'Documento comercial. No es un comprobante de pago tributario';

    public function availability(Order $order): array
    {
        $payments = $this->approvedPayments($order);
        $summary = $payments->sum(fn (array $payment) => $payment['amount_minor']);
        $completedRefunds = collect($payments)->sum(fn (array $payment) => $payment['completed_refund_minor']);
        $pendingRefunds = collect($payments)->sum(fn (array $payment) => $payment['pending_refund_minor']);
        $total = $this->minor($order->total);
        $net = max(0, $summary - $completedRefunds);

        return [
            'order_summary_available' => true,
            'payment_constancy_available' => $payments->isNotEmpty(),
            'payment_constancy_unavailable_reason' => $payments->isEmpty()
                ? 'No hay un pago aprobado verificable. Un voucher pendiente de revisión no es suficiente.'
                : null,
            'currency' => 'PEN',
            'approved_amount' => $this->decimal($summary),
            'refunded_amount' => $this->decimal($completedRefunds),
            'pending_refund_amount' => $this->decimal($pendingRefunds),
            'net_received_amount' => $this->decimal($net),
            'remaining_amount' => $this->decimal($order->payment_status === 'refunded' || $order->status === 'canceled' ? 0 : max(0, $total - $net)),
            'payment_progress_label' => $order->payment_status === 'refunded' ? 'Pedido reembolsado'
                : ($order->status === 'canceled' ? 'Pedido cancelado'
                    : ($payments->isEmpty() ? 'Sin pago aprobado verificable' : ($net >= $total ? 'Pago completo' : 'Pago parcial'))),
        ];
    }

    public function orderSummary(Order $order): string
    {
        $order->loadMissing('user:id,name,email');
        $lines = $this->orderSummaryLines($order);
        $seller = BusinessProfile::query()->first();

        return $this->renderPdf('commercial-documents.order-summary', [
            'order' => $order,
            'lines' => $lines,
            'seller' => $seller,
            'customer' => $order->user,
            'paymentStatusLabel' => $this->paymentStatusLabel($order->payment_status),
            'notice' => self::NOTICE,
        ]);
    }

    /**
     * Project order lines from their stored snapshots, never current catalog pricing.
     */
    public function orderSummaryLines(Order $order): Collection
    {
        $order->loadMissing(['items.product:id,name']);

        return $order->items->map(function ($item): array {
            $quantity = (int) $item->quantity;
            $appliedMinor = $this->minor($item->price) * $quantity;
            $regularUnit = $item->regular_price ?? $item->price;
            $regularMinor = $this->minor($regularUnit) * $quantity;

            return [
                'name' => $item->product_name ?: ($item->product?->name ?: 'Producto no disponible'),
                'name_is_current_fallback' => blank($item->product_name) && filled($item->product?->name),
                'quantity' => $quantity,
                'unit_price' => $item->price,
                'regular_unit_price' => $regularUnit,
                'line_subtotal' => $item->subtotal ?? $this->decimal($appliedMinor),
                'line_savings' => $this->decimal(max(0, $regularMinor - $appliedMinor)),
            ];
        });
    }

    public function paymentConstancy(Order $order): string
    {
        $payments = $this->approvedPayments($order);
        abort_if($payments->isEmpty(), 404, 'No hay un pago aprobado verificable para emitir una constancia.');
        $seller = BusinessProfile::query()->first();
        $approvedMinor = $payments->sum(fn (array $payment) => $payment['amount_minor']);
        $completedRefundMinor = $payments->sum(fn (array $payment) => $payment['completed_refund_minor']);
        $hasClosedOrder = $order->payment_status === 'refunded' || $order->status === 'canceled';
        $remainingMinor = $hasClosedOrder ? 0 : max(0, $this->minor($order->total) - $approvedMinor + $completedRefundMinor);

        return $this->renderPdf('commercial-documents.payment-constancy', [
            'order' => $order->loadMissing('user:id,name,email'),
            'seller' => $seller,
            'payments' => $payments,
            'approvedAmount' => $this->decimal($approvedMinor),
            'refundedAmount' => $this->decimal($completedRefundMinor),
            'netReceivedAmount' => $this->decimal(max(0, $approvedMinor - $completedRefundMinor)),
            'remainingAmount' => $this->decimal($remainingMinor),
            'orderMarkedRefunded' => $order->payment_status === 'refunded',
            'orderCanceled' => $order->status === 'canceled',
            'paymentProgressLabel' => $order->payment_status === 'refunded' ? 'Pedido reembolsado'
                : ($order->status === 'canceled' ? 'Pedido cancelado' : ($remainingMinor > 0 ? 'Pago parcial' : 'Pago completo')),
            'notice' => self::NOTICE,
        ]);
    }

    private function renderPdf(string $view, array $data): string
    {
        $pdf = Pdf::loadView($view, $data)->setPaper('a4')->setOptions([
            'isRemoteEnabled' => false,
            'isPhpEnabled' => false,
            'isJavascriptEnabled' => false,
            'defaultFont' => 'DejaVu Sans',
        ]);
        $pdf->render();
        $font = $pdf->getDomPDF()->getFontMetrics()->getFont('DejaVu Sans', 'bold');
        $pdf->getDomPDF()->getCanvas()->page_text(42, 800, self::NOTICE, $font, 9, [0.60, 0.17, 0.05]);

        return $pdf->output();
    }

    private function approvedPayments(Order $order): Collection
    {
        $transactions = PaymentTransaction::query()
            ->where('order_id', $order->id)
            ->where('transaction_type', PaymentTransaction::PAYMENT)
            ->where('status', PaymentTransaction::APPROVED)
            ->where(function ($query) {
                $query->whereNotNull('confirmed_at')->orWhereNotNull('verified_at')->orWhereNotNull('collected_at');
            })
            ->where('amount', '>', 0)
            ->orderBy('confirmed_at')
            ->orderBy('verified_at')
            ->orderBy('id')
            ->get();

        if ($transactions->isEmpty()) {
            return collect();
        }

        $refunds = DB::table('payment_refunds')
            ->whereIn('original_payment_transaction_id', $transactions->pluck('id'))
            ->get()
            ->groupBy('original_payment_transaction_id');

        return $transactions->map(function (PaymentTransaction $transaction) use ($refunds): array {
            $records = $refunds->get($transaction->id, collect());
            $completed = $records->where('status', 'completed')->sum(fn ($refund) => $this->minor($refund->amount));
            $pending = $records->where('status', 'pending')->sum(fn ($refund) => $this->minor($refund->amount));
            $reference = $transaction->manual_reference ?: $transaction->external_reference;

            return [
                'amount' => $transaction->amount,
                'currency' => $transaction->currency ?: 'PEN',
                'date' => ($transaction->confirmed_at ?: ($transaction->verified_at ?: $transaction->collected_at))?->timezone(config('app.timezone'))->format('d/m/Y H:i'),
                'method' => $this->methodLabel($transaction->payment_method, $transaction->collection_method),
                'reference' => $this->maskedReference($reference),
                'amount_minor' => $transaction->currency === 'PEN' ? $this->minor($transaction->amount) : 0,
                'completed_refund_minor' => $transaction->currency === 'PEN' ? $completed : 0,
                'pending_refund_minor' => $transaction->currency === 'PEN' ? $pending : 0,
                'refund_status' => $records->contains(fn ($refund) => $refund->status === 'pending') ? 'Devolución en revisión'
                    : ($records->contains(fn ($refund) => $refund->status === 'completed') ? 'Devolución completada'
                        : ($records->contains(fn ($refund) => $refund->status === 'failed') ? 'Devolución no completada' : null)),
            ];
        });
    }

    private function methodLabel(?string $method, ?string $collection): string
    {
        return match ($method) {
            'transferencia', 'bank_transfer' => 'Transferencia bancaria',
            'card', 'tarjeta', 'mercadopago' => 'Tarjeta',
            'cash_on_delivery', 'contraentrega', 'contra_entrega' => 'Pago contraentrega',
            'cash', 'pago_en_sede' => 'Pago en sede',
            default => filled($method) ? 'Otro método de pago' : 'Método no registrado',
        };
    }

    private function paymentStatusLabel(?string $status): string
    {
        return match ($status) {
            'pending' => 'Pendiente',
            'partial' => 'Pago parcial',
            'approved' => 'Pagado',
            'rejected' => 'Rechazado',
            'refunded' => 'Reembolsado',
            default => 'No registrado',
        };
    }

    private function maskedReference(?string $reference): string
    {
        $normalized = preg_replace('/\D+/', '', (string) $reference);

        return $normalized === '' ? (filled($reference) ? 'Registrada (dato protegido)' : 'No registrada') : '••••'.substr($normalized, -4);
    }

    private function minor(mixed $amount): int
    {
        $value = trim((string) ($amount ?? '0'));
        if (! preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $value, $parts)) {
            return 0;
        }
        $whole = $parts[1];
        $fraction = str_pad(($parts[2] ?? '0'), 2, '0');

        return ((int) $whole * 100) + (int) $fraction;
    }

    private function decimal(int $minor): string
    {
        return number_format($minor / 100, 2, '.', '');
    }
}
