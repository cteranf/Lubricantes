<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\InventoryService;
use App\Services\OrderStateService;
use App\Services\PaymentGateway\PaymentGatewayFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PaymentController extends Controller
{
    public function __construct(private OrderStateService $orderStateService, private InventoryService $inventoryService) {}

    /**
     * Create payment preference/checkout
     */
    public function createPayment(Request $request)
    {
        $validated = $request->validate([
            'order_id' => 'required|exists:orders,id',
        ]);

        $order = Order::with('items.product', 'user')->findOrFail($validated['order_id']);

        // Verify order belongs to authenticated user
        if ($order->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // Prepare order data for payment gateway
        $orderData = [
            'order_id' => $order->id,
            'items' => $order->items->map(function ($item) {
                return [
                    'name' => $item->product->name,
                    'quantity' => $item->quantity,
                    'price' => $item->price,
                ];
            })->toArray(),
            'customer' => [
                'name' => $order->user->name,
                'email' => $order->user->email,
            ],
            'total' => $order->total,
        ];

        try {
            $gateway = PaymentGatewayFactory::create();
            $paymentData = $gateway->createPayment($orderData);

            // Update order with payment info
            $order->update([
                'payment_id' => $paymentData['id'],
                'payment_status' => 'pending',
                'payment_data' => $paymentData,
            ]);

            return response()->json([
                'payment_id' => $paymentData['id'],
                'checkout_url' => config('payment.mercadopago.sandbox')
                    ? $paymentData['sandbox_init_point']
                    : $paymentData['init_point'],
            ]);
        } catch (\Exception $e) {
            Log::error('Payment creation failed: '.$e->getMessage());

            return response()->json(['message' => 'Error creating payment'], 500);
        }
    }

    /**
     * Verify payment status
     */
    public function verifyPayment(Request $request, $paymentId)
    {
        try {
            $gateway = PaymentGatewayFactory::create();
            $paymentData = $gateway->verifyPayment($paymentId);

            $order = $this->findOrderForPayment($paymentId, $paymentData);

            if (! $order) {
                abort(404);
            }

            if (! $request->user()->isAdmin() && $order->user_id !== $request->user()->id) {
                abort(404);
            }

            $this->assertPaymentMatchesOrder($order, $paymentData);
            $order = $this->updateOrderStatus($order, $paymentData);

            return response()->json([
                'payment' => $paymentData,
                'order_id' => $order->id,
                'order_status' => $order->status,
                'payment_status' => $order->payment_status,
            ]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Payment verification failed: '.$e->getMessage());

            return response()->json(['message' => 'Error verifying payment'], 500);
        }
    }

    /**
     * Resolve Mercado Pago's browser return without trusting its status query string.
     */
    public function handleReturn(Request $request)
    {
        $validated = $request->validate([
            'payment_id' => 'nullable|string|max:255',
            'collection_id' => 'nullable|string|max:255',
            'preference_id' => 'nullable|string|max:255',
            'external_reference' => 'nullable|integer|min:1',
            'result' => 'nullable|in:approved,pending,rejected,canceled',
        ]);

        $transactionId = $validated['payment_id'] ?? $validated['collection_id'] ?? null;
        $paymentData = null;

        if ($transactionId) {
            try {
                $paymentData = PaymentGatewayFactory::create()->verifyPayment($transactionId);
            } catch (\Exception $e) {
                Log::warning('Could not verify payment return', [
                    'payment_id' => $transactionId,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        $verifiedOrderId = $paymentData['external_reference'] ?? null;
        $requestedOrderId = $validated['external_reference'] ?? null;
        $orderId = $verifiedOrderId ?: $requestedOrderId;

        $query = Order::where('user_id', $request->user()->id);

        if ($orderId) {
            $query->whereKey($orderId);
        } elseif (! empty($validated['preference_id'])) {
            $query->where('payment_id', $validated['preference_id']);
        } else {
            return response()->json([
                'message' => 'No se pudo identificar el pedido devuelto por la pasarela.',
            ], 422);
        }

        $order = $query->firstOrFail();

        if (! empty($validated['preference_id'])
            && $order->payment_id
            && ! hash_equals((string) $order->payment_id, (string) $validated['preference_id'])) {
            abort(404);
        }

        if ($paymentData) {
            $this->assertPaymentMatchesOrder($order, $paymentData);
            $order = $this->updateOrderStatus($order, $paymentData);
        }

        $displayStatus = $order->payment_status;
        if ($displayStatus === 'pending'
            && in_array($validated['result'] ?? null, ['pending', 'rejected', 'canceled'], true)) {
            $displayStatus = $validated['result'];
        }

        return response()->json([
            'order_id' => $order->id,
            'order_status' => $order->status,
            'payment_status' => $order->payment_status,
            'display_status' => $displayStatus,
        ]);
    }

    /**
     * Handle payment webhook
     */
    public function webhook(Request $request)
    {
        try {
            $gateway = PaymentGatewayFactory::create();
            $paymentData = $gateway->handleWebhook($request->all());

            // Find order by external reference or payment_id
            $order = Order::where('payment_id', $paymentData['id'])
                ->orWhere('id', $paymentData['external_reference'] ?? null)
                ->first();

            if ($order) {
                $this->assertPaymentMatchesOrder($order, $paymentData);
                $this->updateOrderStatus($order, $paymentData);
            }

            return response()->json(['status' => 'ok'], 200);
        } catch (ValidationException $e) {
            Log::warning('Webhook payment data did not match the order', [
                'errors' => $e->errors(),
            ]);

            return response()->json(['status' => 'invalid'], 422);
        } catch (\Exception $e) {
            Log::error('Webhook processing failed: '.$e->getMessage());

            return response()->json(['status' => 'error'], 500);
        }
    }

    /**
     * Update order status based on payment status
     */
    private function updateOrderStatus(Order $order, array $paymentData): Order
    {
        $order = DB::transaction(function () use ($order, $paymentData) {
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $wasCanceled = in_array($locked->status, ['canceled', 'rejected'], true);
            $updated = $this->orderStateService->applyPaymentStatus($locked, $paymentData);
            if (! $wasCanceled && in_array($updated->status, ['canceled', 'rejected'], true)) {
                $updated->items()->with(['product', 'warehouse'])->get()
                    ->each(fn ($item) => $this->inventoryService->returnCancellation($item));
            }
            return $updated;
        });

        Log::info("Order #{$order->id} updated to payment_status: {$order->payment_status}");

        return $order;
    }

    private function findOrderForPayment(string $paymentId, array $paymentData): ?Order
    {
        $externalReference = $paymentData['external_reference'] ?? null;

        return Order::when($externalReference, fn ($query) => $query->whereKey($externalReference))
            ->when(! $externalReference, fn ($query) => $query->where('payment_id', $paymentId))
            ->first();
    }

    private function assertPaymentMatchesOrder(Order $order, array $paymentData): void
    {
        if (config('payment.default_gateway') === 'mock') {
            return;
        }

        if (array_key_exists('transaction_amount', $paymentData)
            && abs((float) $paymentData['transaction_amount'] - (float) $order->total) > 0.01) {
            throw ValidationException::withMessages([
                'payment' => ['El monto confirmado no coincide con el total del pedido.'],
            ]);
        }

        if (! empty($paymentData['currency_id'])
            && $paymentData['currency_id'] !== config('payment.currency')) {
            throw ValidationException::withMessages([
                'payment' => ['La moneda confirmada no coincide con la moneda del pedido.'],
            ]);
        }
    }
}
