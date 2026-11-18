<?php

namespace App\Support;

use App\Models\InventoryReservation;
use App\Models\Order;
use App\Models\PaymentSubmission;

class EffectiveInventoryReservationStatus
{
    public static function forItem(
        Order $order,
        ?InventoryReservation $reservation,
        ?string $paymentSubmissionStatus = null
    ): ?string {
        if (! $reservation) {
            return null;
        }

        if ($reservation->status !== InventoryReservation::ACTIVE) {
            return $reservation->status;
        }

        if (self::hasProofUnderReview($order, $paymentSubmissionStatus)) {
            return InventoryReservation::ACTIVE;
        }

        $now = now();
        $orderExpired = $order->reserved_until && $order->reserved_until->lessThanOrEqualTo($now);
        $reservationExpired = $reservation->expires_at && $reservation->expires_at->lessThanOrEqualTo($now);

        return $orderExpired || $reservationExpired
            ? InventoryReservation::EXPIRED
            : InventoryReservation::ACTIVE;
    }

    public static function hasProofUnderReview(Order $order, ?string $paymentSubmissionStatus = null): bool
    {
        if (in_array($paymentSubmissionStatus, [PaymentSubmission::PENDING_REVIEW, PaymentSubmission::OBSERVED, PaymentSubmission::APPROVED, PaymentSubmission::MANUAL_RESOLUTION_REQUIRED], true)) {
            return true;
        }

        $data = $order->payment_data ?? [];
        $proofStatus = strtolower((string) ($data['transfer_proof_status'] ?? $data['proof_status'] ?? $data['comprobante_status'] ?? ''));

        return in_array($proofStatus, ['submitted', 'pending', 'under_review', 'received', 'en_revision', 'pendiente'], true)
            || (bool) array_intersect(array_keys($data), ['transfer_proof', 'proof_url', 'receipt_url', 'voucher_url', 'comprobante_url']);
    }
}
