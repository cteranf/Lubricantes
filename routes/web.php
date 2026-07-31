<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
*/

// Mock Payment Routes (for testing without real gateway)
Route::get('/mock-payment/{paymentId}', function ($paymentId) {
    $orderId = request('order_id');
    $order = \App\Models\Order::whereKey($orderId)
        ->where('payment_id', $paymentId)
        ->firstOrFail();

    $approveUrl = \Illuminate\Support\Facades\URL::temporarySignedRoute(
        'mock.payment.approve',
        now()->addMinutes(30),
        ['paymentId' => $paymentId, 'order_id' => $order->id]
    );

    return view('mock-payment', [
        'paymentId' => $paymentId,
        'orderId' => $order->id,
        'approveUrl' => $approveUrl,
    ]);
})->middleware(['payment.mock', 'signed'])->name('mock.payment');

Route::post('/mock-payment/{paymentId}/approve', function ($paymentId) {
    $orderId = request('order_id');

    $order = \App\Models\Order::whereKey($orderId)
        ->where('payment_id', $paymentId)
        ->firstOrFail();

    app(\App\Services\OrderStateService::class)->applyPaymentStatus($order, [
        'id' => $paymentId,
        'status' => 'approved',
        'status_detail' => 'accredited',
        'external_reference' => $order->id,
        'payment_method_id' => 'mock',
    ]);

    return redirect('/orders/success/'.$orderId);
})->middleware(['payment.mock', 'signed'])->name('mock.payment.approve');

// SPA Catch-all (must be last)
Route::get('/{any}', function () {
    return view('welcome');
})->where('any', '.*');
