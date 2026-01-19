<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\PaymentGateway\PaymentGatewayFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PaymentController extends Controller
{
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
            Log::error('Payment creation failed: ' . $e->getMessage());
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

            // Find order by payment_id
            $order = Order::where('payment_id', $paymentId)->first();

            if ($order) {
                $this->updateOrderStatus($order, $paymentData);
            }

            return response()->json($paymentData);
        } catch (\Exception $e) {
            Log::error('Payment verification failed: ' . $e->getMessage());
            return response()->json(['message' => 'Error verifying payment'], 500);
        }
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
                $this->updateOrderStatus($order, $paymentData);
            }

            return response()->json(['status' => 'ok'], 200);
        } catch (\Exception $e) {
            Log::error('Webhook processing failed: ' . $e->getMessage());
            return response()->json(['status' => 'error'], 500);
        }
    }

    /**
     * Update order status based on payment status
     */
    private function updateOrderStatus(Order $order, array $paymentData)
    {
        $paymentStatus = $paymentData['status'];

        // Map payment status to order status
        $statusMap = [
            'approved' => 'confirmed',
            'rejected' => 'canceled',
            'pending' => 'pending',
            'in_process' => 'pending',
        ];

        $order->update([
            'payment_status' => $paymentStatus,
            'status' => $statusMap[$paymentStatus] ?? 'pending',
            'payment_data' => array_merge($order->payment_data ?? [], $paymentData),
        ]);

        Log::info("Order #{$order->id} updated to payment_status: {$paymentStatus}");
    }
}
