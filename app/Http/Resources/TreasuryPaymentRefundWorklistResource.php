<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/** Accepts only a prepared read row; never an Eloquent model or relationship. */
class TreasuryPaymentRefundWorklistResource extends JsonResource
{
    public function toArray($request): array
    {
        $row = $this->resource;

        return [
            'order_id' => $row['order_id'], 'order_number' => $row['order_number'],
            'order_payment_status' => $row['order_payment_status'], 'original_payment_method' => $row['original_payment_method'],
            'amount' => $row['amount'], 'currency' => $row['currency'],
            'refund' => $row['refund'] === null ? null : [
                'id' => $row['refund']['id'], 'status' => $row['refund']['status'],
                'created_at' => $row['refund']['created_at'], 'completed_at' => $row['refund']['completed_at'],
            ],
            'can_mark_refund_pending' => $row['can_mark_refund_pending'], 'can_complete_refund' => $row['can_complete_refund'],
            'requires_manual_review' => $row['requires_manual_review'], 'relevant_at' => $row['relevant_at'], 'message' => $row['message'],
        ];
    }
}
