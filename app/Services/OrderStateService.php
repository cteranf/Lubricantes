<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Validation\ValidationException;

class OrderStateService
{
    public const COMMERCIAL_STATUSES = [
        'pending',
        'confirmed',
        'shipped',
        'delivered',
        'canceled',
        'rejected',
    ];

    public function transitionTracking(Order $order, string $targetStatus, array $attributes = []): Order
    {
        $this->assertTrackingTransition($order, $targetStatus);

        $updates = array_intersect_key($attributes, array_flip([
            'tracking_notes',
            'estimated_delivery_date',
        ]));

        $updates['tracking_status'] = $targetStatus;
        $updates['status'] = $this->commercialStatusForTracking($targetStatus);

        if (in_array($targetStatus, ['delivered', 'picked_up'], true) && ! $order->delivered_at) {
            $updates['delivered_at'] = now();
        }

        $order->update($updates);

        return $order->refresh();
    }

    public function transitionCommercial(Order $order, string $targetStatus): Order
    {
        if (! in_array($targetStatus, self::COMMERCIAL_STATUSES, true)) {
            $this->invalid('El estado del pedido no es válido.');
        }

        if ($targetStatus === $order->status) {
            return $order;
        }

        if (in_array($order->status, ['canceled', 'rejected', 'delivered'], true)) {
            $this->invalid('Un pedido finalizado o cancelado no puede volver al flujo operativo.');
        }

        if ($targetStatus === 'rejected' && $order->payment_status === 'approved') {
            $this->invalid('Un pago aprobado no puede marcarse como rechazado.');
        }

        $targetTracking = match ($targetStatus) {
            'pending' => 'pending',
            'confirmed' => 'confirmed',
            'shipped' => $order->delivery_type === 'delivery' ? 'shipped' : null,
            'delivered' => $order->delivery_type === 'delivery' ? 'delivered' : 'picked_up',
            'canceled', 'rejected' => 'canceled',
        };

        if ($targetTracking === null) {
            $this->invalid('El estado solicitado no es compatible con el tipo de entrega.');
        }

        $this->assertTrackingTransition($order, $targetTracking);

        $updates = [
            'status' => $targetStatus,
            'tracking_status' => $targetTracking,
        ];

        if ($targetStatus === 'rejected') {
            $updates['payment_status'] = 'rejected';
        }

        if (in_array($targetTracking, ['delivered', 'picked_up'], true) && ! $order->delivered_at) {
            $updates['delivered_at'] = now();
        }

        $order->update($updates);

        return $order->refresh();
    }

    public function applyPaymentStatus(Order $order, array $paymentData): Order
    {
        $paymentStatus = $paymentData['status'] ?? 'pending';

        if (! in_array($paymentStatus, ['approved', 'rejected', 'pending', 'in_process', 'refunded'], true)) {
            $paymentStatus = 'pending';
        }

        $normalizedPaymentStatus = $paymentStatus === 'in_process' ? 'pending' : $paymentStatus;
        $updates = [
            'payment_status' => $normalizedPaymentStatus,
            'payment_data' => array_merge($order->payment_data ?? [], $paymentData),
        ];

        if ($normalizedPaymentStatus === 'approved') {
            if (in_array($order->status, ['canceled', 'rejected'], true)) {
                $this->invalid('Un pedido cancelado no puede reactivarse mediante una notificación de pago.');
            }

            if ($order->tracking_status === 'pending') {
                $updates['status'] = 'confirmed';
                $updates['tracking_status'] = 'confirmed';
            }
        } elseif (in_array($normalizedPaymentStatus, ['rejected', 'refunded'], true)) {
            if (in_array($order->tracking_status, ['delivered', 'picked_up'], true)) {
                $this->invalid('No se puede cancelar por pago un pedido ya finalizado.');
            }

            $updates['status'] = 'canceled';
            $updates['tracking_status'] = 'canceled';
        }

        $order->update($updates);

        return $order->refresh();
    }

    public function assertTrackingTransition(Order $order, string $targetStatus): void
    {
        $currentStatus = $order->tracking_status;

        if ($targetStatus === $currentStatus) {
            return;
        }

        if ($order->isTrackingTerminal() || in_array($order->status, ['canceled', 'rejected', 'delivered'], true)) {
            $this->invalid('Un pedido finalizado o cancelado no puede cambiar de estado.');
        }

        if ($targetStatus === 'canceled') {
            return;
        }

        $flow = $order->trackingFlow();
        $currentIndex = array_search($currentStatus, $flow, true);
        $targetIndex = array_search($targetStatus, $flow, true);

        if ($currentIndex === false || $targetIndex === false) {
            $this->invalid('El estado de seguimiento no es compatible con el tipo de entrega.');
        }

        if ($targetIndex !== $currentIndex + 1) {
            $this->invalid('La transición de seguimiento debe respetar el orden del flujo actual.');
        }

        if ($targetStatus === 'confirmed'
            && $order->payment_method === 'card'
            && $order->payment_status !== 'approved') {
            $this->invalid('El pago debe estar aprobado antes de confirmar este pedido.');
        }
    }

    private function commercialStatusForTracking(string $trackingStatus): string
    {
        return match ($trackingStatus) {
            'pending' => 'pending',
            'confirmed', 'processing', 'ready_for_pickup' => 'confirmed',
            'shipped' => 'shipped',
            'delivered', 'picked_up' => 'delivered',
            'canceled' => 'canceled',
        };
    }

    private function invalid(string $message): void
    {
        throw ValidationException::withMessages([
            'status' => [$message],
        ]);
    }
}
