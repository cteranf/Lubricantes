<?php

use App\Http\Controllers\Api\V1\Admin\DashboardController;
use App\Http\Controllers\Api\V1\Admin\OrderController as AdminOrderController;
use App\Http\Controllers\Api\V1\Admin\ProductController as AdminProductController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BrandController;
use App\Http\Controllers\Api\V1\CartController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\ProductController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

Route::prefix('v1')->group(function () {

    // Auth Public
    Route::post('/auth/register', [AuthController::class, 'register']);
    Route::post('/auth/login', [AuthController::class, 'login']);

    // Public Catalog
    Route::apiResource('products', ProductController::class)->only(['index', 'show']);
    Route::apiResource('categories', CategoryController::class)->only(['index', 'show']);
    Route::apiResource('brands', BrandController::class)->only(['index', 'show']);
    Route::get('/sliders', [App\Http\Controllers\Api\V1\SliderController::class, 'index']);
    Route::get('/news', [App\Http\Controllers\Api\V1\NewsController::class, 'index']);
    Route::get('/news/{slug}', [App\Http\Controllers\Api\V1\NewsController::class, 'show']);

    // Payment Webhook (public - no auth required)
    Route::post('/payment/webhook', [App\Http\Controllers\Api\V1\PaymentController::class, 'webhook']);

    // Protected Routes
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/auth/user', [AuthController::class, 'user']);

        // Cart & Checkout
        Route::post('/cart', [CartController::class, 'store']); // Sync cart
        Route::apiResource('orders', OrderController::class);
        Route::get('/orders/{id}/tracking', [App\Http\Controllers\Api\V1\OrderTrackingController::class, 'show']);

        // Payment
        Route::post('/payment/create', [App\Http\Controllers\Api\V1\PaymentController::class, 'createPayment']);
        Route::get('/payment/verify/{paymentId}', [App\Http\Controllers\Api\V1\PaymentController::class, 'verifyPayment']);

        // Admin Routes
        Route::middleware('is_admin')->prefix('admin')->group(function () {
            Route::get('/dashboard', [DashboardController::class, 'index']);
            Route::apiResource('products', AdminProductController::class);
            Route::apiResource('orders', AdminOrderController::class);
            Route::put('/orders/{id}/tracking', [AdminOrderController::class, 'updateTracking']);
            Route::apiResource('sliders', App\Http\Controllers\Api\V1\Admin\SliderController::class);
            Route::apiResource('categories', App\Http\Controllers\Api\V1\Admin\CategoryController::class);
            Route::apiResource('news', App\Http\Controllers\Api\V1\Admin\NewsController::class);
        });
    });
});
