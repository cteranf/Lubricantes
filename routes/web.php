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

    return view('mock-payment', [
        'paymentId' => $paymentId,
        'orderId' => $orderId,
    ]);
})->name('mock.payment');

Route::post('/mock-payment/{paymentId}/approve', function ($paymentId) {
    $orderId = request('order_id');

    // Update order status directly
    $order = \App\Models\Order::find($orderId);
    if ($order) {
        $order->update([
            'payment_status' => 'approved',
            'status' => 'confirmed',
            'payment_id' => $paymentId,
        ]);
    }

    return redirect('/orders/success/' . $orderId);
})->name('mock.payment.approve');

// SPA Catch-all (must be last)
Route::get('/{any}', function () {
    return view('welcome');
})->where('any', '.*');
