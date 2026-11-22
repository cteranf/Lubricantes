<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\PaymentSubmission;
use App\Services\OrderDeliveryService;
use App\Services\ProductReviewService;
use App\Support\EffectiveInventoryReservationStatus;
use App\Support\TransferPaymentInstallments;
use Illuminate\Http\Request;

class OrderTrackingController extends Controller
{
    public function __construct(private OrderDeliveryService $delivery, private ProductReviewService $reviews) {}

    /**
     * Get tracking information for a specific order
     */
    public function show(Request $request, $id)
    {
        $order = Order::with(['items.product.images', 'items.warehouse', 'items.reservation', 'user', 'delivery:id,order_id,status', 'paymentSubmissions:id,order_id,status,amount,expected_amount,channel,submitted_at,reviewed_at,decision_reason', 'paymentSubmissions.resolutionCase:id,payment_submission_id,status'])->findOrFail($id);

        $user = $request->user();

        // Tracking is a customer-owned surface; administrative order management
        // remains available through the separate admin endpoints.
        if ((int) $user->getKey() !== (int) $order->user_id) {
            abort(404);
        }

        $reviewAccessByProduct = $this->reviews->trackingReviewAccess($order, $user);
        $delivery = $order->isPickup() ? null : $this->delivery->getCustomerTracking($order);
        $paymentSubmissionStatus = $order->paymentSubmissions->first(fn ($submission) => in_array($submission->status, [PaymentSubmission::PENDING_REVIEW, PaymentSubmission::OBSERVED, PaymentSubmission::APPROVED, PaymentSubmission::MANUAL_RESOLUTION_REQUIRED], true))?->status;
        $reservationStatuses = $order->items
            ->map(fn ($item) => EffectiveInventoryReservationStatus::forItem($order, $item->reservation, $paymentSubmissionStatus))
            ->filter()
            ->unique()
            ->values();
        $reservationStatus = $reservationStatuses->count() === 1
            ? $reservationStatuses->first()
            : ($reservationStatuses->isEmpty()
                ? ($order->fulfillment_status === Order::FULFILLMENT_RESERVED ? 'none' : null)
                : 'mixed');

        return response()->json([
            'order' => [
                'id' => $order->id,
                'order_number' => '#'.str_pad($order->id, 6, '0', STR_PAD_LEFT),
                'status' => $order->status,
                'delivery_type' => $order->delivery_type,
                'tracking_status' => $order->tracking_status,
                'fulfillment_status' => $order->effectiveFulfillmentStatus(),
                'tracking_notes' => $order->tracking_notes,
                'estimated_delivery_date' => $order->estimated_delivery_date?->format('Y-m-d'),
                'delivered_at' => $this->iso($order->delivered_at),
                'created_at' => $this->iso($order->created_at),
                'reserved_until' => $this->iso($order->reserved_until),
                'total' => $order->total,
                'payment_method' => $order->payment_method,
                'payment_status' => $order->payment_status,
                'receipt_request' => $order->requested_receipt_type ? [
                    'type' => $order->requested_receipt_type,
                    'identity_type' => $order->requested_identity_type,
                    'identity_number' => $order->requested_identity_number,
                    'identity_required' => (bool) $order->requested_identity_required,
                    'recipient_name' => $order->requested_recipient_name,
                    'billing_address' => $order->requested_billing_address,
                    'email' => $order->requested_receipt_email,
                    'status' => 'requested_not_issued',
                ] : null,
                'payment_submission_status' => $paymentSubmissionStatus,
                'payment_installments' => $order->payment_method === 'transferencia' ? [
                    'accounting' => TransferPaymentInstallments::summary($order, $order->paymentSubmissions),
                    'operations' => $order->paymentSubmissions->map(fn ($payment) => [
                        'id' => $payment->id,
                        'amount' => $payment->amount,
                        'amount_not_recorded' => $payment->amount === null,
                        'channel' => $payment->channel,
                        'status' => $payment->status,
                        'operation_number_masked' => $payment->maskedOperationNumber(),
                        'submitted_at' => $this->iso($payment->submitted_at),
                        'reason' => in_array($payment->status, ['observed', 'rejected'], true) ? $payment->decision_reason : null,
                    ])->values(),
                ] : null,
                'reservation_status' => $reservationStatus,
                'paid_at' => $this->iso($order->paid_at),
                'shipping_info' => $order->shipping_info,
                'subtotal' => $order->subtotal ?? $order->total,
                'discount_total' => $order->discount_total ?? '0.00',
                'shipping_amount' => $order->shipping_amount ?? '0.00',
                'shipping_quote' => $order->isPickup() ? null : [
                    'zone_code' => $order->shipping_zone_code_snapshot,
                    'zone_name' => $order->shipping_zone_name_snapshot,
                    'department' => $order->shipping_department_snapshot,
                    'province' => $order->shipping_province_snapshot,
                    'district' => $order->shipping_district_snapshot,
                    'ubigeo' => $order->shipping_ubigeo_snapshot,
                    'estimated_days_min' => $order->shipping_estimated_days_min_snapshot,
                    'estimated_days_max' => $order->shipping_estimated_days_max_snapshot,
                    'historical_configuration' => $order->shipping_zone_id === null,
                ],
                'pickup' => $order->isPickup() ? [
                    'branch_id' => $order->pickup_branch_id,
                    'code' => $order->pickup_branch_code_snapshot,
                    'name' => $order->pickup_branch_name_snapshot,
                    'address' => $order->pickup_address_snapshot,
                    'district' => $order->pickup_district_snapshot,
                    'business_hours' => $order->pickup_business_hours_snapshot,
                    'instructions' => $order->pickup_instructions_snapshot,
                    'ready_at' => $this->iso($order->ready_for_pickup_at),
                    'deadline_at' => $this->iso($order->pickup_deadline_at),
                    'picked_up_at' => $this->iso($order->picked_up_at),
                    'deadline_status' => $order->pickupDeadlineStatus(),
                ] : null,
                'items' => $order->items->map(function ($item) use ($reviewAccessByProduct, $order, $paymentSubmissionStatus) {
                    $itemPayload = [
                        'name' => $item->product_name ?? $item->product?->name ?? 'Producto no disponible',
                        'quantity' => $item->quantity,
                        'regular_price' => $item->regular_price,
                        'price' => $item->price,
                        'subtotal' => $item->subtotal,
                        'image_url' => $item->product?->image_url,
                        'warehouse' => $item->warehouse ? ['id' => $item->warehouse->id, 'name' => $item->warehouse->name] : null,
                        'reservation' => $item->reservation ? [
                            'status' => EffectiveInventoryReservationStatus::forItem($order, $item->reservation, $paymentSubmissionStatus),
                            'quantity' => $item->reservation->quantity,
                            'expires_at' => $this->iso($item->reservation->expires_at),
                            'consumed_at' => $this->iso($item->reservation->consumed_at),
                            'released_at' => $this->iso($item->reservation->released_at),
                        ] : null,
                    ];

                    $productId = (int) $item->product_id;
                    if (array_key_exists($productId, $reviewAccessByProduct)) {
                        $itemPayload['review_access'] = $reviewAccessByProduct[$productId];
                    }

                    return $itemPayload;
                }),
            ],
            'timeline' => $this->fulfillmentTimeline($order, $delivery),
            'delivery' => $delivery,
        ]);
    }

    private function fulfillmentTimeline(Order $order, ?array $delivery): array
    {
        $state = $order->effectiveFulfillmentStatus();
        if ($state === Order::FULFILLMENT_CANCELED) {
            return [
                ['status' => 'received', 'label' => 'Pedido recibido', 'completed' => true, 'active' => false, 'icon' => 'pi pi-check', 'date' => $this->iso($order->created_at)],
                ['status' => 'canceled', 'label' => 'Cancelado', 'completed' => false, 'active' => true, 'icon' => 'pi pi-times-circle', 'date' => null],
            ];
        }
        $rank = ['reserved' => 0, 'preparing' => 1, 'ready' => 2, 'delivered' => 3];
        $current = $rank[$state] ?? 0;
        $steps = [['status' => 'received', 'label' => 'Pedido recibido', 'rank' => 0, 'icon' => 'pi pi-shopping-cart', 'date' => $this->iso($order->created_at)]];
        if ($order->payment_method !== 'contra_entrega') {
            $steps[] = ['status' => 'payment', 'label' => 'Pago confirmado', 'rank' => 0, 'icon' => 'pi pi-credit-card', 'date' => $this->iso($order->paid_at), 'payment' => true];
        }
        $steps[] = ['status' => 'preparing', 'label' => 'En preparación', 'rank' => 1, 'icon' => 'pi pi-cog', 'date' => $this->iso($order->preparing_at)];
        $steps[] = ['status' => 'ready', 'label' => $order->isPickup() ? 'Listo para recoger' : 'Listo para entrega', 'rank' => 2, 'icon' => 'pi pi-box', 'date' => $this->iso($order->ready_at)];
        if ($delivery) {
            $deliverySteps = $this->deliverySteps($delivery);
            foreach ($deliverySteps as $step) {
                $steps[] = $step;
            }
        }
        $steps[] = ['status' => 'delivered', 'label' => $order->isPickup() ? 'Pedido recogido' : 'Entregado', 'rank' => 3, 'icon' => 'pi pi-check-circle', 'date' => $this->iso($order->isPickup() ? $order->picked_up_at : $order->delivered_at)];
        if ($order->payment_method === 'contra_entrega' && $order->payment_status === 'approved') {
            $steps[] = ['status' => 'payment', 'label' => 'Pago confirmado', 'rank' => 3, 'icon' => 'pi pi-wallet', 'date' => $this->iso($order->paid_at), 'payment' => true];
        }

        return collect($steps)->map(function ($step) use ($current, $state, $order, $delivery) {
            $payment = $step['payment'] ?? false;
            if ($step['delivery_step'] ?? false) {
                $flow = $delivery['method'] === 'store_pickup' ? ['awaiting_pickup', 'delivered'] : ['dispatched', 'out_for_delivery', 'failed_attempt', 'rescheduled', 'delivered'];
                $now = array_search($delivery['status'], $flow, true);
                $at = array_search($step['status'], $flow, true);
                $step['completed'] = $now !== false && $at !== false && $now > $at;
                $step['active'] = $step['status'] === $delivery['status'];
            } else {
                $step['completed'] = $payment ? $order->payment_status === 'approved' : $current > $step['rank'];
                $step['active'] = $payment ? $state === 'reserved' && $order->payment_status === 'approved' : $step['status'] === $state || ($step['status'] === 'received' && $state === 'reserved' && $order->payment_status !== 'approved');
            }
            unset($step['rank'],$step['payment'],$step['delivery_step']);

            return $step;
        })->all();
    }

    private function deliverySteps(array $delivery): array
    {
        $status = $delivery['status'];
        $steps = [];
        if ($delivery['method'] === 'store_pickup' && in_array($status, ['awaiting_pickup', 'delivered'], true)) {
            $steps[] = ['status' => 'awaiting_pickup', 'label' => 'Esperando recojo', 'rank' => 2, 'delivery_step' => true, 'icon' => 'pi pi-shopping-bag', 'date' => $delivery['scheduled_at']];
        }
        if ($delivery['method'] !== 'store_pickup' && in_array($status, ['dispatched', 'out_for_delivery', 'failed_attempt', 'rescheduled', 'delivered'], true)) {
            $steps[] = ['status' => 'dispatched', 'label' => 'Despachado', 'rank' => 2, 'delivery_step' => true, 'icon' => 'pi pi-truck', 'date' => null];
        }
        if (in_array($status, ['out_for_delivery', 'failed_attempt', 'rescheduled', 'delivered'], true)) {
            $steps[] = ['status' => 'out_for_delivery', 'label' => 'En camino', 'rank' => 2, 'delivery_step' => true, 'icon' => 'pi pi-send', 'date' => null];
        }
        if ($status === 'failed_attempt') {
            $steps[] = ['status' => 'failed_attempt', 'label' => 'Intento de entrega no completado', 'rank' => 2, 'delivery_step' => true, 'icon' => 'pi pi-exclamation-triangle', 'date' => null];
        }
        if ($status === 'rescheduled') {
            $steps[] = ['status' => 'rescheduled', 'label' => 'Entrega reprogramada', 'rank' => 2, 'delivery_step' => true, 'icon' => 'pi pi-calendar', 'date' => $delivery['scheduled_at']];
        }

        return $steps;
    }

    private function iso($date): ?string
    {
        return $date?->copy()->utc()->toIso8601String();
    }
}
