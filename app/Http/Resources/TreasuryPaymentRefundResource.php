<?php

namespace App\Http\Resources;

use App\Models\PaymentRefund;
use Illuminate\Http\Resources\Json\JsonResource;

class TreasuryPaymentRefundResource extends JsonResource
{
    public function __construct(PaymentRefund $refund, private int $orderId, private string $paymentMethod)
    {
        parent::__construct($refund);
    }

    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'order_id' => $this->orderId,
            'status' => $this->status,
            'amount' => (string) $this->amount,
            'currency' => $this->currency,
            'original_payment_method' => $this->paymentMethod,
            'created_at' => $this->created_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'message' => $this->status === PaymentRefund::PENDING ? 'Reembolso pendiente de finalización.' : 'Reembolso completado.',
            // Presentation only: the completion POST revalidates the entire state.
            'can_complete' => $this->status === PaymentRefund::PENDING,
        ];
    }
}
