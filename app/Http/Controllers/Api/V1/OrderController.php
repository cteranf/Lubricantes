<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\InventoryException;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Order;
use App\Models\Product;
use App\Models\UserAddress;
use App\Services\InventoryReservationExpirationService;
use App\Services\InventoryService;
use App\Services\MoneyService;
use App\Services\ShippingRateService;
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
    ) {}

    public function index(Request $request)
    {
        return $request->user()->orders()->with(['items.product', 'items.warehouse', 'items.reservation'])->latest()->get()
            ->each(fn (Order $order) => $this->appendPickupPresentation($order));
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'checkout_token' => 'nullable|string|max:100',
            'shipping_info' => 'nullable|array',
            'shipping_info.phone' => 'nullable|string|max:30',
            'payment_method' => 'required|in:card,transferencia,contra_entrega,pago_en_sede',
            'delivery_type' => 'required|in:delivery,pickup',
            'pickup_branch_id' => 'required_if:delivery_type,pickup|prohibited_if:delivery_type,delivery|nullable|integer',
            'address_id' => 'required_if:delivery_type,delivery|prohibited_if:delivery_type,pickup|nullable|integer',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer|distinct|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
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

                foreach ($data['items'] as $requestedItem) {
                    $product = Product::whereKey($requestedItem['product_id'])->where('is_active', true)->first();
                    if (! $product) {
                        throw ValidationException::withMessages(['items' => ['Uno de los productos ya no está disponible.']]);
                    }
                    $price = (string) ($product->sale_price ?? $product->price);
                    $lineSubtotalMinor = $this->money->toMinor($price) * $requestedItem['quantity'];
                    $lineSubtotal = $this->money->fromMinor($lineSubtotalMinor);
                    $subtotalMinor += $lineSubtotalMinor;
                    $itemsToCreate[] = ['product' => $product, 'quantity' => $requestedItem['quantity'], 'price' => $price, 'subtotal' => $lineSubtotal];
                }

                $discountMinor = 0;
                $shippingMinor = $shippingQuote ? $this->money->toMinor($shippingQuote['shipping_amount']) : 0;
                $subtotal = $this->money->fromMinor($subtotalMinor);
                $discountTotal = $this->money->fromMinor($discountMinor);
                $shippingAmount = $this->money->fromMinor($shippingMinor);
                $total = $this->money->fromMinor($subtotalMinor - $discountMinor + $shippingMinor);
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
                ]);

                foreach ($itemsToCreate as $itemData) {
                    $item = $order->items()->create([
                        'product_id' => $itemData['product']->id, 'warehouse_id' => $warehouse->id,
                        'quantity' => $itemData['quantity'], 'price' => $itemData['price'], 'subtotal' => $itemData['subtotal'],
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
