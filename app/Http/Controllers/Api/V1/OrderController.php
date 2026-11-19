<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\InventoryException;
use App\Http\Controllers\Controller;
use App\Http\Resources\CustomerOrderHistoryResource;
use App\Models\Branch;
use App\Models\InventoryReservation;
use App\Models\Order;
use App\Models\PaymentSubmission;
use App\Models\Product;
use App\Models\UserAddress;
use App\Services\CheckoutPriceConfirmationService;
use App\Services\CheckoutTaxQuoteService;
use App\Services\InventoryReservationExpirationService;
use App\Services\InventoryService;
use App\Services\MoneyService;
use App\Services\ProductPricingService;
use App\Services\ShippingRateService;
use App\Support\SalesReceiptRules;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OrderController extends Controller
{
    public function __construct(
        private InventoryService $inventory,
        private ShippingRateService $shippingRates,
        private MoneyService $money,
        private \App\Services\PaymentSettingService $paymentSettings,
        private InventoryReservationExpirationService $reservationExpiration,
        private SalesReceiptRules $receiptRules,
        private \App\Services\OrderTaxCalculationService $taxes,
        private CheckoutTaxQuoteService $taxQuotes,
    ) {}

    public function index(Request $request)
    {
        $orders = $request->user()->orders()
            ->with([
                'items:id,order_id,product_id,quantity,price,subtotal',
                'items.product:id,name,image_path',
                'items.product.images:id,product_id,image_path,variants,is_primary,sort_order,processing_status',
                'delivery:id,order_id,status',
            ])
            ->latest()
            ->get();

        return response()->json($orders
            ->map(fn (Order $order) => (new CustomerOrderHistoryResource($order))->resolve($request))
            ->values());
    }

    /**
     * Return a current, non-reserving quote for restarting an expired transfer order.
     */
    public function repurchaseQuote(Request $request, Order $order)
    {
        abort_unless((int) $request->user()->id === (int) $order->user_id, 404);

        $reservationExpired = ($order->reserved_until && $order->reserved_until->isPast())
            || $order->reservations()->where('status', InventoryReservation::EXPIRED)->exists()
            || $order->reservations()->where('status', InventoryReservation::ACTIVE)->where('expires_at', '<=', now())->exists();
        $terminalTracking = in_array($order->tracking_status, ['delivered', 'picked_up'], true);
        $hasSubmission = PaymentSubmission::query()->where('order_id', $order->id)->exists();

        if ($order->payment_method !== 'transferencia'
            || $order->payment_status !== 'pending'
            || ! $reservationExpired
            || $hasSubmission
            || $terminalTracking
            || in_array($order->status, ['rejected', 'delivered'], true)) {
            return response()->json([
                'code' => 'repurchase_not_available',
                'message' => 'Este pedido no está disponible para volver a comprar sus productos.',
            ], 409);
        }

        $order->load(['items.product.activePromotion', 'items.product.images']);
        $pricing = app(ProductPricingService::class);
        $availableByProduct = [];
        try {
            $availableByProduct = $this->inventory->sellableStockForProducts(
                $order->items->pluck('product')->filter(fn ($product) => $product && $product->is_active)
            );
        } catch (InventoryException) {
            // A missing active checkout warehouse makes every line unavailable.
        }
        $items = $order->items->map(function ($item) use ($availableByProduct, $pricing): array {
            $product = $item->product;
            if (! $product || ! $product->is_active) {
                return [
                    'product_id' => null,
                    'name' => $item->product_name ?? 'Producto no disponible',
                    'quantity' => (int) $item->quantity,
                    'product_available' => false,
                    'available_quantity' => 0,
                    'regular_price' => null,
                    'price' => null,
                    'image_url' => null,
                    'slug' => null,
                ];
            }

            $quote = $pricing->quote($product);
            $availableQuantity = (int) ($availableByProduct[$product->id] ?? 0);

            return [
                'product_id' => (int) $product->id,
                'name' => $product->name,
                'slug' => $product->slug,
                'quantity' => (int) $item->quantity,
                'product_available' => true,
                'available_quantity' => $availableQuantity,
                'regular_price' => $quote['regular_price'],
                'price' => $quote['price'],
                'image_url' => $product->image_url,
            ];
        })->values();

        return response()->json(['items' => $items]);
    }

    public function receiptOptions(): \Illuminate\Http\JsonResponse
    {
        return response()->json(['data' => $this->receiptRules->settings()]);
    }

    public function store(Request $request)
    {
        if (! $request->exists('receipt_request')) {
            $settings = $this->receiptRules->settings();
            $request->merge([
                'receipt_settings_version' => $settings['version'],
                'receipt_request' => [
                    'type' => $settings['default_type'],
                    'email' => $request->user()?->email,
                ],
            ]);
        }
        $validator = Validator::make($request->all(), [
            'checkout_token' => 'nullable|string|max:100',
            'expected_total' => ['nullable', 'regex:/^\\d+(?:\\.\\d{1,2})?$/'],
            'price_confirmation_token' => ['nullable', 'string', 'max:10000'],
            'tax_quote_token' => ['nullable', 'string', 'max:10000'],
            'shipping_info' => 'nullable|array',
            'shipping_info.phone' => 'nullable|string|max:30',
            'payment_method' => 'required|in:card,transferencia,contra_entrega,pago_en_sede',
            'delivery_type' => 'required|in:delivery,pickup',
            'pickup_branch_id' => 'required_if:delivery_type,pickup|prohibited_if:delivery_type,delivery|nullable|integer',
            'address_id' => 'required_if:delivery_type,delivery|prohibited_if:delivery_type,pickup|nullable|integer',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer|distinct|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'receipt_settings_version' => ['required', 'string', 'max:100'],
            'receipt_request' => ['required', 'array:type,identity_type,identity_number,recipient_name,billing_address,email'],
            'receipt_request.type' => ['required', 'string', 'in:boleta,factura'],
            'receipt_request.identity_type' => ['nullable', 'string', 'max:20'],
            'receipt_request.identity_number' => ['nullable', 'string', 'max:32'],
            'receipt_request.recipient_name' => ['nullable', 'string', 'max:255'],
            'receipt_request.billing_address' => ['nullable', 'string', 'max:255'],
            'receipt_request.email' => ['required', 'email', 'max:255'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }
        $data = $validator->validated();
        $data['checkout_token'] = $data['checkout_token'] ?? Str::uuid()->toString();

        try {
            $duplicate = false;
            $order = DB::transaction(function () use ($request, $data, &$duplicate) {
                if (! $this->paymentSettings->isMethodAllowed($data['payment_method'], $data['delivery_type'])) {
                    throw ValidationException::withMessages([
                        'payment_method' => ['El método de pago seleccionado no está disponible para la modalidad elegida.'],
                    ]);
                }
                $existing = Order::where('checkout_token', $data['checkout_token'])
                    ->where('user_id', $request->user()->id)
                    ->lockForUpdate()
                    ->first();
                if ($existing) {
                    $duplicate = true;

                    $isPendingUnpaid = $existing->status === 'pending' && $existing->payment_status === 'pending';
                    $isReservationExpired = ($existing->reserved_until && $existing->reserved_until->isPast())
                        || ($isPendingUnpaid && $existing->reservations()->where('status', \App\Models\InventoryReservation::EXPIRED)->exists());

                    if ($isPendingUnpaid && $isReservationExpired) {
                        $this->logReservationExpired('POST /api/v1/orders', $data['checkout_token'], $existing, 'existing_token_expired');
                        if ($existing->reservations()->where('status', \App\Models\InventoryReservation::ACTIVE)->exists()) {
                            $this->reservationExpiration->expireOrder($existing->id);
                        }
                        $existing->update(['status' => 'canceled', 'tracking_status' => 'canceled']);

                        return response()->json([
                            'code' => 'reservation_expired',
                            'message' => 'La reserva de stock de este pedido ha expirado.',
                            'can_retry' => true,
                        ], 409);
                    }

                    if (in_array($existing->status, ['canceled', 'rejected'], true)) {
                        $this->logReservationExpired('POST /api/v1/orders', $data['checkout_token'], $existing, 'existing_order_terminal');

                        return response()->json([
                            'code' => 'reservation_expired',
                            'message' => 'El pedido o su reserva ya no se encuentran vigentes.',
                            'can_retry' => true,
                        ], 409);
                    }

                    return $existing;
                }
                $foreignToken = Order::where('checkout_token', $data['checkout_token'])
                    ->where('user_id', '!=', $request->user()->id)
                    ->lockForUpdate()
                    ->first(['id']);
                if ($foreignToken) {
                    throw ValidationException::withMessages(['checkout_token' => ['La clave de checkout ya está en uso. Genere una nueva e inténtelo otra vez.']]);
                }

                $pickupBranch = null;
                $shippingAddress = null;
                $shippingQuote = null;
                if ($data['delivery_type'] === 'pickup') {
                    $pickupBranch = Branch::whereKey($data['pickup_branch_id'])->lockForUpdate()->first();
                    if (! $pickupBranch || ! $pickupBranch->is_active || ! $pickupBranch->allows_pickup || ! $pickupBranch->serves_public) {
                        throw ValidationException::withMessages(['pickup_branch_id' => ['La sede seleccionada ya no está disponible para recojo. Seleccione otra modalidad o sede.']]);
                    }
                } else {
                    $shippingAddress = UserAddress::where('user_id', $request->user()->id)
                        ->where('is_active', true)->whereKey($data['address_id'])->lockForUpdate()->first();
                    if (! $shippingAddress) {
                        throw ValidationException::withMessages(['address_id' => ['La dirección seleccionada no es válida.']]);
                    }
                    $shippingQuote = $this->shippingRates->quote($shippingAddress, true);
                    if (! $shippingQuote['has_coverage']) {
                        throw ValidationException::withMessages(['address_id' => [$shippingQuote['message']]]);
                    }
                }

                $warehouse = $this->inventory->defaultWarehouse();
                $isOfflinePayment = in_array($data['payment_method'], ['contra_entrega', 'pago_en_sede'], true);
                $reservedUntil = $isOfflinePayment ? null : now()->addMinutes(max(1, (int) config('inventory.reservation_minutes', 30)));
                $reservationExpiresAt = $reservedUntil ?: now()->addYears(10);
                $subtotalMinor = 0;
                $itemsToCreate = [];
                $productIds = collect($data['items'])->pluck('product_id')->map(fn ($id) => (int) $id)->sort()->values();
                $products = Product::query()->whereIn('id', $productIds)->where('is_active', true)
                    ->orderBy('id')->lockForUpdate()->with('activePromotion')->get()->keyBy('id');
                if ($products->count() !== $productIds->count()) {
                    throw ValidationException::withMessages(['items' => ['Uno de los productos ya no está disponible.']]);
                }
                $pricing = app(ProductPricingService::class);
                foreach ($data['items'] as $requestedItem) {
                    /** @var Product|null $product */
                    $product = $products->get((int) $requestedItem['product_id']);
                    if (! $product) {
                        throw ValidationException::withMessages(['items' => ['Uno de los productos ya no está disponible.']]);
                    }
                    $quote = $pricing->quote($product);
                    $price = $quote['price'];
                    $regularPrice = $quote['regular_price'];
                    $lineSubtotalMinor = $this->money->toMinor($price) * $requestedItem['quantity'];
                    $lineSubtotal = $this->money->fromMinor($lineSubtotalMinor);
                    $subtotalMinor += $lineSubtotalMinor;
                    $itemsToCreate[] = [
                        'product' => $product,
                        'quantity' => $requestedItem['quantity'],
                        'price' => $price,
                        'regular_price' => $regularPrice,
                        'promotion_id' => $quote['promotion_id'],
                        'subtotal' => $lineSubtotal,
                    ];
                }

                $discountMinor = 0;
                $shippingMinor = $shippingQuote ? $this->money->toMinor($shippingQuote['shipping_amount']) : 0;
                $subtotal = $this->money->fromMinor($subtotalMinor);
                $discountTotal = $this->money->fromMinor($discountMinor);
                $shippingAmount = $this->money->fromMinor($shippingMinor);
                $total = $this->money->fromMinor($subtotalMinor - $discountMinor + $shippingMinor);
                $taxSnapshot = $this->taxes->calculate($itemsToCreate, $shippingMinor, $discountMinor, true);
                if ($this->money->toMinor((string) $taxSnapshot['totals']['gross_minor']) !== $subtotalMinor - $discountMinor + $shippingMinor) {
                    throw ValidationException::withMessages(['tax_snapshot' => ['El desglose tributario no coincide con el total comercial confirmado. No se modificó el importe del pedido.']]);
                }
                $taxQuoteInput = [
                    'items' => array_map(static fn (array $item): array => [
                        'product_id' => (int) $item['product']->id,
                        'quantity' => (int) $item['quantity'],
                        'regular_price' => (string) $item['regular_price'],
                        'price' => (string) $item['price'],
                        'subtotal' => (string) $item['subtotal'],
                        'tax_affectation' => $item['product']->tax_affectation,
                    ], $itemsToCreate),
                    'shipping_amount' => $shippingAmount,
                    'tax_snapshot' => $taxSnapshot,
                ];
                $receiptSettings = $this->receiptRules->settings(lock: true);
                if (! hash_equals((string) $receiptSettings['version'], (string) $data['receipt_settings_version'])) {
                    return response()->json([
                        'code' => 'receipt_settings_changed',
                        'message' => 'La configuración de comprobantes cambió. Actualiza tu elección antes de continuar.',
                        'receipt_options' => $receiptSettings,
                    ], 409);
                }
                $priceSnapshot = [
                    'user_id' => (int) $request->user()->id,
                    'checkout_token' => (string) $data['checkout_token'],
                    'delivery_type' => (string) $data['delivery_type'],
                    'address_id' => isset($data['address_id']) ? (int) $data['address_id'] : null,
                    'pickup_branch_id' => isset($data['pickup_branch_id']) ? (int) $data['pickup_branch_id'] : null,
                    'shipping_zone_id' => $shippingQuote['zone']->id ?? null,
                    'shipping_rate_id' => $shippingQuote['rate']->id ?? null,
                    'subtotal_minor' => $subtotalMinor,
                    'shipping_minor' => $shippingMinor,
                    'total_minor' => $subtotalMinor - $discountMinor + $shippingMinor,
                    'tax_calculation_version' => $taxSnapshot['calculation_version'],
                    'tax_configuration_version' => $taxSnapshot['configuration_version'],
                    'items' => array_map(fn (array $item) => [
                        'product_id' => (int) $item['product']->id,
                        'quantity' => (int) $item['quantity'],
                        'regular_price' => (string) $item['regular_price'],
                        'price' => (string) $item['price'],
                        'promotion_id' => $item['promotion_id'],
                    ], $itemsToCreate),
                ];
                if (isset($data['expected_total'])) {
                    try {
                        $expectedTotalMinor = $this->money->toMinor((string) $data['expected_total']);
                    } catch (\InvalidArgumentException) {
                        throw ValidationException::withMessages(['expected_total' => ['El total revisado no es válido.']]);
                    }
                    $priceConfirmation = app(CheckoutPriceConfirmationService::class);
                    if ($expectedTotalMinor !== (int) $priceSnapshot['total_minor']
                        || (isset($data['price_confirmation_token']) && ! $priceConfirmation->confirms($data['price_confirmation_token'], $priceSnapshot, $expectedTotalMinor))) {
                        return response()->json([
                            'code' => 'price_changed',
                            'message' => 'El precio o el envío cambió. Revisa el nuevo total antes de confirmar el pedido.',
                            'regular_subtotal' => $this->money->fromMinor($subtotalMinor),
                            'shipping_amount' => $shippingAmount,
                            'total' => $total,
                            'business_timezone' => config('app.timezone'),
                            'items' => array_map(fn (array $item) => [
                                'product_id' => (int) $item['product']->id,
                                'name' => (string) $item['product']->name,
                                'quantity' => (int) $item['quantity'],
                                'regular_price' => (string) $item['regular_price'],
                                'price' => (string) $item['price'],
                                'subtotal' => (string) $item['subtotal'],
                            ], $itemsToCreate),
                            'tax_preview' => $taxSnapshot,
                            'tax_quote_token' => $this->taxQuotes->issue($taxQuoteInput),
                            'confirmation_token' => $priceConfirmation->issue($priceSnapshot),
                        ], 409);
                    }
                }
                if (isset($data['tax_quote_token']) && ! $this->taxQuotes->confirms($data['tax_quote_token'], $taxQuoteInput)) {
                    return response()->json([
                        'code' => 'tax_calculation_changed',
                        'message' => 'El cálculo tributario cambió desde que revisaste el checkout. Revisa el desglose actualizado antes de continuar.',
                        'total' => $total,
                        'shipping_amount' => $shippingAmount,
                        'tax_preview' => $taxSnapshot,
                        'items' => array_map(fn (array $item) => [
                            'product_id' => (int) $item['product']->id,
                            'regular_price' => (string) $item['regular_price'],
                            'price' => (string) $item['price'],
                        ], $itemsToCreate),
                        'tax_quote_token' => $this->taxQuotes->issue($taxQuoteInput),
                    ], 409);
                }
                $receiptSnapshot = $this->receiptRules->validateRequest(
                    $data['receipt_request'],
                    (int) $priceSnapshot['total_minor'],
                    $receiptSettings
                );
                $shippingInfo = $data['delivery_type'] === 'delivery'
                    ? [
                        'recipient_name' => $shippingAddress->recipient_name,
                        'phone' => $shippingAddress->phone,
                        'address' => $shippingAddress->address,
                        'reference' => $shippingAddress->reference,
                        'department' => $shippingAddress->department,
                        'province' => $shippingAddress->province,
                        'district' => $shippingAddress->district,
                        'ubigeo' => $shippingAddress->ubigeo,
                    ]
                    : array_filter(['phone' => $data['shipping_info']['phone'] ?? null]);

                $order = Order::create([
                    'user_id' => $request->user()->id,
                    'status' => $isOfflinePayment ? 'confirmed' : 'pending',
                    'subtotal' => $subtotal,
                    'discount_total' => $discountTotal,
                    'shipping_amount' => $shippingAmount,
                    'shipping_address_id' => $shippingAddress?->id,
                    'shipping_zone_id' => $shippingQuote['zone']->id ?? null,
                    'shipping_rate_id' => $shippingQuote['rate']->id ?? null,
                    'shipping_zone_code_snapshot' => $shippingQuote['zone']->code ?? null,
                    'shipping_zone_name_snapshot' => $shippingQuote['zone']->name ?? null,
                    'shipping_department_snapshot' => $shippingAddress?->department,
                    'shipping_province_snapshot' => $shippingAddress?->province,
                    'shipping_district_snapshot' => $shippingAddress?->district,
                    'shipping_ubigeo_snapshot' => $shippingAddress?->ubigeo,
                    'shipping_estimated_days_min_snapshot' => $shippingQuote['estimated_days_min'] ?? null,
                    'shipping_estimated_days_max_snapshot' => $shippingQuote['estimated_days_max'] ?? null,
                    'total' => $total,
                    'tax_calculation_status' => $taxSnapshot['status'],
                    'tax_snapshot' => $taxSnapshot,
                    'tax_calculation_version' => $taxSnapshot['calculation_version'],
                    'checkout_token' => $data['checkout_token'],
                    'shipping_info' => $shippingInfo,
                    'payment_method' => $data['payment_method'],
                    'delivery_type' => $data['delivery_type'],
                    'pickup_branch_id' => $pickupBranch?->id,
                    'pickup_branch_code_snapshot' => $pickupBranch?->code,
                    'pickup_branch_name_snapshot' => $pickupBranch?->name,
                    'pickup_address_snapshot' => $pickupBranch?->address,
                    'pickup_district_snapshot' => $pickupBranch?->district,
                    'pickup_business_hours_snapshot' => $pickupBranch?->business_hours,
                    'pickup_instructions_snapshot' => $pickupBranch?->pickup_instructions,
                    'tracking_status' => $isOfflinePayment ? 'confirmed' : 'pending',
                    'fulfillment_status' => Order::FULFILLMENT_RESERVED,
                    'delivery_flow_version' => 1,
                    'reserved_until' => $reservedUntil,
                    ...$receiptSnapshot,
                ]);

                foreach ($itemsToCreate as $itemIndex => $itemData) {
                    $item = $order->items()->create([
                        'product_id' => $itemData['product']->id, 'warehouse_id' => $warehouse->id,
                        'product_name' => $itemData['product']->name,
                        'quantity' => $itemData['quantity'], 'regular_price' => $itemData['regular_price'],
                        'promotion_id' => $itemData['promotion_id'], 'price' => $itemData['price'], 'subtotal' => $itemData['subtotal'],
                        'tax_snapshot' => $taxSnapshot['lines'][$itemIndex] ?? null,
                    ]);
                    $item->setRelation('product', $itemData['product']);
                    $item->setRelation('warehouse', $warehouse);
                    $this->inventory->reserveForOrder($item, $reservationExpiresAt);
                }

                return $order;
            });

            if ($order instanceof \Illuminate\Http\JsonResponse) {
                return $order;
            }

            return response()->json($this->appendPickupPresentation($order->load(['items.warehouse', 'items.reservation'])), $duplicate ? 200 : 201);
        } catch (ValidationException $e) {
            throw $e;
        } catch (InventoryException $e) {
            throw ValidationException::withMessages(['items' => [$e->getMessage()]]);
        } catch (QueryException $e) {
            $existing = Order::where('checkout_token', $data['checkout_token'])->where('user_id', $request->user()->id)->first();
            if ($existing) {
                if ($existing->reserved_until && $existing->reserved_until->isPast()) {
                    $this->logReservationExpired('POST /api/v1/orders', $data['checkout_token'], $existing, 'query_exception_existing_token');

                    return response()->json([
                        'code' => 'reservation_expired',
                        'message' => 'La reserva de stock de este pedido ha expirado.',
                        'can_retry' => true,
                    ], 409);
                }

                return response()->json($this->appendPickupPresentation($existing->load(['items.warehouse', 'items.reservation'])), 200);
            }
            if (Order::where('checkout_token', $data['checkout_token'])->where('user_id', '!=', $request->user()->id)->exists()) {
                throw ValidationException::withMessages(['checkout_token' => ['La clave de checkout ya está en uso. Genere una nueva e inténtelo otra vez.']]);
            }
            throw $e;
        } catch (\Throwable $e) {
            Log::error('Order creation failed', ['user_id' => $request->user()->id, 'message' => $e->getMessage()]);

            return response()->json(['message' => 'No se pudo crear el pedido. Inténtalo nuevamente.'], 500);
        }
    }

    public function show(Request $request, $id)
    {
        $order = $request->user()->orders()->with(['items.product', 'items.warehouse', 'items.reservation'])->findOrFail($id);

        return response()->json($this->appendPickupPresentation($order));
    }

    private function appendPickupPresentation(Order $order): Order
    {
        $order->setAttribute('pickup_deadline_status', $order->pickupDeadlineStatus());
        if ($order->subtotal === null) {
            $order->setAttribute('subtotal', $order->total);
        }

        return $order;
    }

    private function logReservationExpired(string $endpoint, string $checkoutToken, Order $order, string $reason): void
    {
        Log::info('Reservation expired response', [
            'endpoint' => $endpoint,
            'checkout_token' => $checkoutToken,
            'matched_checkout_token' => $order->checkout_token,
            'order_id' => $order->id,
            'payment_method' => $order->payment_method,
            'order_status' => $order->status,
            'payment_status' => $order->payment_status,
            'reserved_until' => optional($order->reserved_until)->toIso8601String(),
            'reservation_status' => $order->reservations()->pluck('status')->implode(','),
            'reservation_expires_at' => $order->reservations()->pluck('expires_at')->map(fn ($value) => optional($value)->toIso8601String())->implode(','),
            'now' => now()->toIso8601String(),
            'expiration_reason' => $reason,
        ]);
    }
}
