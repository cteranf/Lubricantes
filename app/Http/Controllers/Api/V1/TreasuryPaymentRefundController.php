<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\PaymentRefundConflictException;
use App\Http\Controllers\Controller;
use App\Http\Requests\CompleteTreasuryPaymentRefundRequest;
use App\Http\Requests\MarkTreasuryPaymentRefundPendingRequest;
use App\Http\Resources\TreasuryPaymentRefundResource;
use App\Models\Order;
use App\Models\PaymentRefund;
use App\Services\TreasuryPaymentRefundService;
use Illuminate\Http\Exceptions\HttpResponseException;

class TreasuryPaymentRefundController extends Controller
{
    public function __construct(private TreasuryPaymentRefundService $refunds) {}

    public function store(MarkTreasuryPaymentRefundPendingRequest $request, string $orderId)
    {
        // A1's certified signature requires a persisted Order; only its ID is needed.
        $order = Order::query()->select('id')->find($this->positiveId($orderId));
        if (! $order) {
            $this->notFound();
        }
        try {
            $refund = $this->refunds->markPending($order, $request->user(), $request->validated('reason'));
        } catch (PaymentRefundConflictException) {
            return $this->conflict();
        }

        return $this->response($refund);
    }

    public function complete(CompleteTreasuryPaymentRefundRequest $request, string $refundId)
    {
        $id = $this->positiveId($refundId);
        if (! PaymentRefund::query()->whereKey($id)->exists()) {
            $this->notFound();
        }
        try {
            $completed = $this->refunds->complete($id, (int) $request->user()->id, $request->validated('external_reference'));
        } catch (PaymentRefundConflictException) {
            return $this->conflict();
        }

        return $this->response($completed);
    }

    private function positiveId(string $value): int
    {
        $normalized = ltrim($value, '0');
        if (! ctype_digit($value) || $normalized === '' || (string) (int) $normalized !== $normalized) {
            $this->notFound();
        }

        return (int) $normalized;
    }

    private function notFound(): never
    {
        throw new HttpResponseException(response()->json(['message' => 'El recurso no existe.'], 404)
            ->header('Cache-Control', 'no-store, private')->header('Pragma', 'no-cache'));
    }

    private function response(PaymentRefund $refund)
    {
        $payment = $refund->originalPaymentTransaction;

        return (new TreasuryPaymentRefundResource($refund, (int) $payment->order_id, $payment->payment_method))
            ->response()->header('Cache-Control', 'no-store, private')->header('Pragma', 'no-cache');
    }

    private function conflict()
    {
        return response()->json([
            'message' => 'No se pudo procesar el reembolso en su estado actual.',
            'code' => 'payment_refund_conflict',
        ], 409)->header('Cache-Control', 'no-store, private')->header('Pragma', 'no-cache');
    }
}
