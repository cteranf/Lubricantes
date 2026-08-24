<?php

use App\Models\Order;
use App\Models\OrderFulfillmentHistory;
use App\Models\PaymentTransaction;
use App\Services\InventoryReservationExpirationService;
use App\Services\InventoryService;
use App\Services\OrderPaymentService;
use App\Services\OrderStateService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
*/

// Mock Payment Gateway Routes (for testing in local/testing environments)
Route::get('/mock-payment/{paymentId}', function ($paymentId, Request $request) {
    $orderId = $request->query('order_id');
    $order = Order::with(['items.product', 'user'])
        ->whereKey($orderId)
        ->where('payment_id', $paymentId)
        ->firstOrFail();

    if ($order->status === 'canceled' || $order->status === 'rejected' || ($order->reserved_until && $order->reserved_until->isPast())) {
        if ($order->status === 'pending' && $order->reserved_until && $order->reserved_until->isPast()) {
            app(InventoryReservationExpirationService::class)->expireOrder($order->id);
            $order->update(['status' => 'canceled', 'tracking_status' => 'canceled']);
        }

        return redirect('/orders/payment-return?payment_id='.$paymentId.'&external_reference='.$order->id.'&result=canceled');
    }

    $processUrl = URL::temporarySignedRoute(
        'mock.payment.process',
        now()->addMinutes(30),
        ['paymentId' => $paymentId, 'order_id' => $order->id]
    );

    $cancelUrl = url('/orders/payment-return?payment_id='.$paymentId.'&external_reference='.$order->id.'&result=canceled');

    return view('mock-payment', [
        'paymentId' => $paymentId,
        'order' => $order,
        'orderId' => $order->id,
        'processUrl' => $processUrl,
        'cancelUrl' => $cancelUrl,
    ]);
})->middleware(['payment.mock', 'signed'])->name('mock.payment');

Route::post('/mock-payment/{paymentId}/process', function ($paymentId, Request $request) {
    $orderId = $request->query('order_id') ?: $request->input('order_id');
    $action = $request->input('action', 'approve');
    $scenario = $request->input('scenario', 'approved');

    return DB::transaction(function () use ($paymentId, $orderId, $action, $scenario) {
        $order = Order::whereKey($orderId)
            ->where('payment_id', $paymentId)
            ->lockForUpdate()
            ->firstOrFail();

        // Check if already approved (idempotency)
        if ($order->payment_status === 'approved' && $order->paid_at) {
            return redirect('/orders/payment-return?payment_id='.$paymentId.'&external_reference='.$order->id.'&result=approved');
        }

        if (in_array($order->status, ['canceled', 'rejected'], true)) {
            return redirect('/orders/payment-return?payment_id='.$paymentId.'&external_reference='.$order->id.'&result=canceled');
        }

        if ($order->reserved_until && $order->reserved_until->isPast()) {
            app(InventoryReservationExpirationService::class)->expireOrder($order->id);
            $order->update(['status' => 'canceled', 'tracking_status' => 'canceled']);

            return redirect('/orders/payment-return?payment_id='.$paymentId.'&external_reference='.$order->id.'&result=rejected');
        }

        if ($action === 'approve' || $scenario === 'approved') {
            app(OrderPaymentService::class)->recordCardAttempt(
                $order,
                $paymentId,
                PaymentTransaction::APPROVED,
                ['scenario' => 'approved', 'gateway' => 'mock']
            );

            app(OrderStateService::class)->applyPaymentStatus($order, [
                'id' => $paymentId,
                'status' => 'approved',
                'status_detail' => 'accredited',
                'external_reference' => $order->id,
                'payment_method_id' => 'mock_card',
            ]);

            $usesReservations = app(InventoryService::class)->orderUsesReservationFlow($order);
            if ($usesReservations) {
                app(InventoryService::class)->consumeOrderReservation($order);
            }

            if (! $order->paid_at) {
                $order->update(['paid_at' => now()]);
            }

            // Record fulfillment history idempotently
            OrderFulfillmentHistory::firstOrCreate([
                'idempotency_key' => 'mock-payment-history-'.$order->id.'-'.$paymentId,
            ], [
                'order_id' => $order->id,
                'from_status' => Order::FULFILLMENT_RESERVED,
                'to_status' => Order::FULFILLMENT_PREPARING,
                'user_id' => $order->user_id,
                'observation' => 'Pago confirmado mediante simulador de tarjeta Visa (entorno de pruebas).',
                'created_at' => now(),
            ]);

            return redirect('/orders/payment-return?payment_id='.$paymentId.'&external_reference='.$order->id.'&result=approved');
        }

        // Rejection branch: record attempt, keep order in pending to allow retry, keep stock reservation
        $reason = match ($scenario) {
            'insufficient_funds' => 'Fondos insuficientes en la tarjeta',
            'expired_card' => 'Tarjeta expirada o bloqueada',
            'declined' => 'Operación denegada por la entidad emisora',
            default => 'Pago rechazado en la simulación',
        };

        app(OrderPaymentService::class)->recordCardAttempt(
            $order,
            $paymentId,
            PaymentTransaction::FAILED,
            ['scenario' => $scenario, 'gateway' => 'mock'],
            $reason
        );

        $order->update(['payment_status' => 'rejected']);

        return redirect('/orders/payment-return?payment_id='.$paymentId.'&external_reference='.$order->id.'&result=rejected');
    });
})->middleware(['payment.mock', 'signed'])->name('mock.payment.process');

// Backward compatible approve route
Route::post('/mock-payment/{paymentId}/approve', function ($paymentId, Request $request) {
    $request->merge(['action' => 'approve', 'scenario' => 'approved']);
    $orderId = $request->query('order_id') ?: $request->input('order_id');

    return DB::transaction(function () use ($paymentId, $orderId) {
        $order = Order::whereKey($orderId)->where('payment_id', $paymentId)->lockForUpdate()->firstOrFail();

        if ($order->reserved_until && $order->reserved_until->isPast()) {
            if ($order->status === 'pending' && $order->reservations()->where('status', \App\Models\InventoryReservation::ACTIVE)->exists()) {
                app(InventoryService::class)->releaseOrderReservation($order, \App\Models\InventoryReservation::EXPIRED);
            }
            $order->update(['status' => 'canceled', 'tracking_status' => 'canceled']);

            return redirect('/orders/payment-return?payment_id='.$paymentId.'&external_reference='.$order->id.'&result=rejected');
        }

        app(OrderPaymentService::class)->recordCardAttempt($order, $paymentId, PaymentTransaction::APPROVED, ['gateway' => 'mock']);
        app(OrderStateService::class)->applyPaymentStatus($order, [
            'id' => $paymentId,
            'status' => 'approved',
            'status_detail' => 'accredited',
            'external_reference' => $order->id,
            'payment_method_id' => 'mock',
        ]);
        if (app(InventoryService::class)->orderUsesReservationFlow($order)) {
            app(InventoryService::class)->consumeOrderReservation($order);
        }
        if (! $order->paid_at) {
            $order->update(['paid_at' => now()]);
        }

        return redirect('/orders/success/'.$orderId);
    });
})->middleware(['payment.mock', 'signed'])->name('mock.payment.approve');

// SPA Catch-all (must be last)
Route::get('/{any}', function () {
    return view('welcome');
})->where('any', '.*');
