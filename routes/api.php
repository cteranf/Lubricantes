<?php

use App\Http\Controllers\Api\V1\Admin\DashboardController;
use App\Http\Controllers\Api\V1\Admin\OrderController as AdminOrderController;
use App\Http\Controllers\Api\V1\Admin\ProductController as AdminProductController;
use App\Http\Controllers\Api\V1\Admin\BranchController as AdminBranchController;
use App\Http\Controllers\Api\V1\Admin\WarehouseController as AdminWarehouseController;
use App\Http\Controllers\Api\V1\Admin\InventoryController as AdminInventoryController;
use App\Http\Controllers\Api\V1\Admin\InventoryMovementController as AdminInventoryMovementController;
use App\Http\Controllers\Api\V1\Admin\CategoryController as AdminCategoryController;
use App\Http\Controllers\Api\V1\Admin\BrandController as AdminBrandController;
use App\Http\Controllers\Api\V1\Admin\OrderFulfillmentController as AdminOrderFulfillmentController;
use App\Http\Controllers\Api\V1\Admin\OrderPickingPackingController as AdminOrderPickingPackingController;
use App\Http\Controllers\Api\V1\Admin\OrderDeliveryController as AdminOrderDeliveryController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BrandController;
use App\Http\Controllers\Api\V1\CartController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\ContactInquiryController;
use App\Http\Controllers\Api\V1\Admin\ContactInquiryController as AdminContactInquiryController;
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

    Route::get('/contact-inquiries/form-context', [ContactInquiryController::class, 'context']);
    Route::post('/contact-inquiries', [ContactInquiryController::class, 'store'])->middleware('throttle:contact-submissions');

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
        Route::apiResource('orders', OrderController::class)->only(['index', 'store', 'show']);
        Route::get('/orders/{id}/tracking', [App\Http\Controllers\Api\V1\OrderTrackingController::class, 'show']);

        // Payment
        Route::post('/payment/create', [App\Http\Controllers\Api\V1\PaymentController::class, 'createPayment']);
        Route::get('/payment/verify/{paymentId}', [App\Http\Controllers\Api\V1\PaymentController::class, 'verifyPayment']);
        Route::get('/payment/return', [App\Http\Controllers\Api\V1\PaymentController::class, 'handleReturn']);

        // Admin Routes
        Route::middleware('is_admin')->prefix('admin')->as('admin.')->group(function () {
            Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
            Route::get('/contact-inquiries/pending-count', [AdminContactInquiryController::class, 'pendingCount'])->name('contact-inquiries.pending-count');
            Route::get('/contact-inquiries/assignable-admins', [AdminContactInquiryController::class, 'assignableAdmins'])->name('contact-inquiries.assignable-admins');
            Route::get('/contact-inquiries', [AdminContactInquiryController::class, 'index'])->name('contact-inquiries.index');
            Route::get('/contact-inquiries/{contactInquiry}', [AdminContactInquiryController::class, 'show'])->name('contact-inquiries.show');
            Route::patch('/contact-inquiries/{contactInquiry}/status', [AdminContactInquiryController::class, 'status'])->name('contact-inquiries.status');
            Route::patch('/contact-inquiries/{contactInquiry}/assignment', [AdminContactInquiryController::class, 'assign'])->name('contact-inquiries.assign');
            Route::post('/contact-inquiries/{contactInquiry}/notes', [AdminContactInquiryController::class, 'note'])->name('contact-inquiries.notes.store');
            Route::post('/contact-inquiries/{contactInquiry}/archive', [AdminContactInquiryController::class, 'archive'])->name('contact-inquiries.archive');
            Route::post('/contact-inquiries/{contactInquiry}/restore', [AdminContactInquiryController::class, 'restore'])->name('contact-inquiries.restore');
            Route::post('/contact-inquiries/{contactInquiry}/actions/email', [AdminContactInquiryController::class, 'email'])->name('contact-inquiries.actions.email');
            Route::post('/contact-inquiries/{contactInquiry}/actions/whatsapp', [AdminContactInquiryController::class, 'whatsapp'])->name('contact-inquiries.actions.whatsapp');
            Route::patch('/products/{product}/status', [AdminProductController::class, 'status'])->name('products.status');
            Route::apiResource('products', AdminProductController::class)->only(['index', 'store', 'update', 'destroy']);
            Route::apiResource('branches', AdminBranchController::class)->only(['index', 'store', 'update']);
            Route::patch('/branches/{branch}/status', [AdminBranchController::class, 'status'])->name('branches.status');
            Route::get('/warehouses/options', [AdminWarehouseController::class, 'options'])->name('warehouses.options');
            Route::apiResource('warehouses', AdminWarehouseController::class)->only(['index', 'store', 'update']);
            Route::patch('/warehouses/{warehouse}/status', [AdminWarehouseController::class, 'status'])->name('warehouses.status');
            Route::get('/inventories', [AdminInventoryController::class, 'index'])->name('inventories.index');
            Route::get('/inventories/{product}', [AdminInventoryController::class, 'show'])->name('inventories.show');
            Route::post('/inventories/adjustments', [AdminInventoryController::class, 'adjustment'])->name('inventories.adjustments');
            Route::post('/inventories/transfers', [AdminInventoryController::class, 'transfer'])->name('inventories.transfers');
            Route::get('/inventory-movements', [AdminInventoryMovementController::class, 'index'])->name('inventory-movements.index');
            Route::apiResource('orders', AdminOrderController::class)->only(['index', 'update']);
            Route::put('/orders/{id}/tracking', [AdminOrderController::class, 'updateTracking'])->name('orders.tracking.update');
            Route::get('/orders/{order}/fulfillment', [AdminOrderFulfillmentController::class, 'show'])->name('orders.fulfillment.show');
            Route::post('/orders/{order}/fulfillment/approve-transfer', [AdminOrderFulfillmentController::class, 'approveTransfer'])->name('orders.fulfillment.approve-transfer');
            Route::post('/orders/{order}/fulfillment/start-preparation', [AdminOrderFulfillmentController::class, 'startPreparation'])->name('orders.fulfillment.start');
            Route::post('/orders/{order}/fulfillment/ready', [AdminOrderFulfillmentController::class, 'ready'])->name('orders.fulfillment.ready');
            Route::post('/orders/{order}/fulfillment/delivered', [AdminOrderFulfillmentController::class, 'delivered'])->name('orders.fulfillment.delivered');
            Route::post('/orders/{order}/fulfillment/cancel', [AdminOrderFulfillmentController::class, 'cancel'])->name('orders.fulfillment.cancel');
            Route::get('/orders/{order}/picking-packing', [AdminOrderPickingPackingController::class, 'show'])->name('orders.handling.show');
            Route::post('/orders/{order}/picking/start', [AdminOrderPickingPackingController::class, 'startPicking'])->name('orders.picking.start');
            Route::patch('/orders/{order}/picking/items/{orderItem}', [AdminOrderPickingPackingController::class, 'updatePicked'])->name('orders.picking.items.update');
            Route::post('/orders/{order}/picking/complete', [AdminOrderPickingPackingController::class, 'completePicking'])->name('orders.picking.complete');
            Route::post('/orders/{order}/packing/start', [AdminOrderPickingPackingController::class, 'startPacking'])->name('orders.packing.start');
            Route::patch('/orders/{order}/packing/items/{orderItem}', [AdminOrderPickingPackingController::class, 'updatePacked'])->name('orders.packing.items.update');
            Route::post('/orders/{order}/packing/complete', [AdminOrderPickingPackingController::class, 'completePacking'])->name('orders.packing.complete');
            Route::post('/orders/{order}/incidents', [AdminOrderPickingPackingController::class, 'reportIncident'])->name('orders.incidents.store');
            Route::patch('/orders/{order}/incidents/{incident}/resolve', [AdminOrderPickingPackingController::class, 'resolveIncident'])->name('orders.incidents.resolve');
            Route::get('/delivery/options', [AdminOrderDeliveryController::class, 'options'])->name('delivery.options');
            Route::get('/orders/{order}/delivery', [AdminOrderDeliveryController::class, 'show'])->name('orders.delivery.show');
            Route::post('/orders/{order}/delivery/initialize', [AdminOrderDeliveryController::class, 'initialize'])->name('orders.delivery.initialize');
            Route::patch('/orders/{order}/delivery/method', [AdminOrderDeliveryController::class, 'method'])->name('orders.delivery.method');
            Route::post('/orders/{order}/delivery/schedule-pickup', [AdminOrderDeliveryController::class, 'schedulePickup'])->name('orders.delivery.schedule-pickup');
            Route::post('/orders/{order}/delivery/assign-driver', [AdminOrderDeliveryController::class, 'assignDriver'])->name('orders.delivery.assign-driver');
            Route::post('/orders/{order}/delivery/assign-courier', [AdminOrderDeliveryController::class, 'assignCourier'])->name('orders.delivery.assign-courier');
            Route::patch('/orders/{order}/delivery/courier-tracking', [AdminOrderDeliveryController::class, 'courierTracking'])->name('orders.delivery.courier-tracking');
            Route::post('/orders/{order}/delivery/dispatch', [AdminOrderDeliveryController::class, 'registerDispatch'])->name('orders.delivery.dispatch');
            Route::post('/orders/{order}/delivery/attempts', [AdminOrderDeliveryController::class, 'startAttempt'])->name('orders.delivery.attempts.store');
            Route::patch('/orders/{order}/delivery/attempts/{attempt}/fail', [AdminOrderDeliveryController::class, 'failAttempt'])->name('orders.delivery.attempts.fail');
            Route::post('/orders/{order}/delivery/out-for-delivery', [AdminOrderDeliveryController::class, 'outForDelivery'])->name('orders.delivery.out');
            Route::post('/orders/{order}/delivery/reschedule', [AdminOrderDeliveryController::class, 'reschedule'])->name('orders.delivery.reschedule');
            Route::post('/orders/{order}/delivery/confirm', [AdminOrderDeliveryController::class, 'confirm'])->name('orders.delivery.confirm');
            Route::post('/orders/{order}/delivery/cancel', [AdminOrderDeliveryController::class, 'cancel'])->name('orders.delivery.cancel');
            Route::get('/orders/{order}/delivery/evidence/{type}', [AdminOrderDeliveryController::class, 'evidence'])->name('orders.delivery.evidence');
            Route::apiResource('sliders', App\Http\Controllers\Api\V1\Admin\SliderController::class)->only(['index', 'store', 'update', 'destroy']);
            Route::get('/categories/options', [AdminCategoryController::class, 'options'])->name('categories.options');
            Route::patch('/categories/{category}/status', [AdminCategoryController::class, 'status'])->name('categories.status');
            Route::apiResource('categories', AdminCategoryController::class)->only(['index', 'show', 'store', 'update', 'destroy']);
            Route::get('/brands/options', [AdminBrandController::class, 'options'])->name('brands.options');
            Route::patch('/brands/{brand}/status', [AdminBrandController::class, 'status'])->name('brands.status');
            Route::apiResource('brands', AdminBrandController::class)->only(['index', 'show', 'store', 'update', 'destroy']);
            Route::apiResource('news', App\Http\Controllers\Api\V1\Admin\NewsController::class)->only(['index', 'store', 'update', 'destroy']);
        });
    });
});
