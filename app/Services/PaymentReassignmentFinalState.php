<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderFulfillmentHistory;
use App\Models\PaymentResolutionCase;
use App\Models\PaymentSubmission;
use App\Models\PaymentSubmissionHistory;
use App\Models\PaymentTransaction;
use Illuminate\Support\Collection;

/** Pure validation of the persisted B1/B2 financial evidence. */
final class PaymentReassignmentFinalState
{
    public static function isCoherent(
        Order $order,
        PaymentSubmission $submission,
        PaymentTransaction $transaction,
        PaymentResolutionCase $case,
        Collection $submissionHistories,
        Collection $resolutionHistories,
        Collection $fulfillmentHistories,
        int $reservationCount,
        bool $forRefundCompletion = false,
    ): bool {
        $openedSubmission = $submissionHistories->where('event', 'resolution_opened')->values();
        $approvedSubmission = $submissionHistories->where('event', PaymentSubmissionHistory::REASSIGNMENT_APPROVED)->values();
        $openedResolution = $resolutionHistories->where('event', 'opened')->values();
        $reassignedResolution = $resolutionHistories->where('event', 'reassigned')->values();
        if ($reservationCount < 1
            || $openedSubmission->count() !== 1
            || $approvedSubmission->count() !== 1
            || $openedResolution->count() !== 1
            || $reassignedResolution->count() !== 1) {
            return false;
        }

        $openedSubmission = $openedSubmission->first();
        $approvedSubmission = $approvedSubmission->first();
        $openedResolution = $openedResolution->first();
        $reassignedResolution = $reassignedResolution->first();
        $reopened = self::strictBoolean($approvedSubmission->safe_metadata, 'reopened_after_expiration');
        if ($reopened === null) {
            return false;
        }

        $openingSubmissionMetadata = [
            'channel' => $submission->channel,
            'expected_amount' => (string) $submission->expected_amount,
            'currency' => 'PEN',
        ];
        $openingResolutionMetadata = [
            'currency' => 'PEN',
            'amount' => (string) $order->total,
        ];
        $finalMetadata = [
            'resolution_case_id' => (int) $case->id,
            'reservation_count' => $reservationCount,
            'reopened_after_expiration' => $reopened,
        ];

        $reopenings = $fulfillmentHistories
            ->where('order_id', $order->id)
            ->where('from_status', Order::FULFILLMENT_CANCELED)
            ->where('to_status', Order::FULFILLMENT_RESERVED)
            ->filter(fn (OrderFulfillmentHistory $history) => (int) ($history->metadata['resolution_case_id'] ?? 0) === (int) $case->id)
            ->values();
        $canonicalReopening = $reopenings->firstWhere(
            'idempotency_key',
            'resolution-reopened-order-'.$order->id.'-case-'.$case->id
        );

        $actors = array_values(array_unique(array_filter([
            $transaction->confirmed_by,
            $case->requested_by,
            $submission->reviewed_by,
            $case->resolved_by,
            $openedSubmission->actor_id,
            $approvedSubmission->actor_id,
            $openedResolution->actor_id,
            $reassignedResolution->actor_id,
        ], fn ($actor) => $actor !== null)));

        $allowedStatuses = [PaymentSubmission::APPROVED, PaymentSubmission::REFUND_PENDING];
        if ($forRefundCompletion) {
            $allowedStatuses[] = PaymentSubmission::REFUNDED;
        }

        return in_array($submission->status, $allowedStatuses, true)
            && $submission->currency === 'PEN'
            && self::minor($submission->expected_amount) === self::minor($order->total)
            && $submission->reviewed_at !== null
            && $submission->decision_reason === 'stock_reassignment_approved'
            && (int) $transaction->order_id === (int) $order->id
            && (int) $transaction->payment_submission_id === (int) $submission->id
            && $transaction->status === PaymentTransaction::APPROVED
            && $transaction->payment_method === 'transferencia'
            && $transaction->transaction_type === PaymentTransaction::PAYMENT
            && $transaction->collection_method === $submission->channel
            && $transaction->currency === 'PEN'
            && self::minor($transaction->amount) === self::minor($order->total)
            && $transaction->approved_scope_key === PaymentTransaction::approvedScopeKeyForOrder($order->id)
            && $transaction->idempotency_key === 'treasury-received-submission-'.$submission->id
            && $transaction->confirmed_at !== null
            && (int) $case->payment_submission_id === (int) $submission->id
            && (int) $case->received_payment_transaction_id === (int) $transaction->id
            && $case->status === PaymentResolutionCase::REASSIGNED
            && $case->type === PaymentResolutionCase::STOCK_REASSIGNMENT
            && $case->currency === 'PEN'
            && self::minor($case->amount) === self::minor($order->total)
            && $case->idempotency_key === 'treasury-resolution-submission-'.$submission->id
            && $case->requested_at !== null
            && $case->funds_received_at !== null
            && $case->resolved_at !== null
            && $case->reason === 'stock_reassignment_approved'
            && count($actors) <= 1
            && self::sameMoment($transaction->confirmed_at, $case->funds_received_at)
            && self::sameMoment($case->funds_received_at, $case->requested_at)
            && self::sameMoment($case->requested_at, $openedSubmission->occurred_at)
            && self::sameMoment($openedSubmission->occurred_at, $openedResolution->occurred_at)
            && self::sameMoment($submission->reviewed_at, $approvedSubmission->occurred_at)
            && self::sameMoment($approvedSubmission->occurred_at, $reassignedResolution->occurred_at)
            && self::sameMoment($reassignedResolution->occurred_at, $case->resolved_at)
            && self::sameMoment($case->resolved_at, $order->paid_at)
            && $openedSubmission->from_status !== null
            && in_array($openedSubmission->from_status, [PaymentSubmission::PENDING_REVIEW, PaymentSubmission::OBSERVED], true)
            && $openedSubmission->to_status === PaymentSubmission::MANUAL_RESOLUTION_REQUIRED
            && $openedSubmission->reason === 'payment_received_reservation_unavailable'
            && self::exactMetadata($openedSubmission->safe_metadata, $openingSubmissionMetadata)
            && $openedResolution->from_status === null
            && $openedResolution->to_status === PaymentResolutionCase::OPEN
            && $openedResolution->reason === 'payment_received_reservation_unavailable'
            && self::exactMetadata($openedResolution->safe_metadata, $openingResolutionMetadata)
            && $approvedSubmission->from_status === PaymentSubmission::MANUAL_RESOLUTION_REQUIRED
            && $approvedSubmission->to_status === PaymentSubmission::APPROVED
            && $approvedSubmission->reason === 'stock_reassignment_approved'
            && self::exactMetadata($approvedSubmission->safe_metadata, $finalMetadata)
            && $reassignedResolution->from_status === PaymentResolutionCase::OPEN
            && $reassignedResolution->to_status === PaymentResolutionCase::REASSIGNED
            && $reassignedResolution->reason === 'stock_reassignment_approved'
            && self::exactMetadata($reassignedResolution->safe_metadata, $finalMetadata)
            && self::exactMetadata($transaction->metadata, ['source' => 'treasury_resolution_opening'])
            && ($reopened ? ($reopenings->count() === 1 && $canonicalReopening !== null) : $reopenings->isEmpty());
    }

    private static function strictBoolean(?array $metadata, string $key): ?bool
    {
        return is_bool($metadata[$key] ?? null) ? $metadata[$key] : null;
    }

    private static function exactMetadata(mixed $actual, array $expected): bool
    {
        if (! is_array($actual)) {
            return false;
        }

        return self::canonical($actual) === self::canonical($expected);
    }

    private static function canonical(array $value): array
    {
        if (array_is_list($value)) {
            return array_map(fn ($item) => is_array($item) ? self::canonical($item) : $item, $value);
        }

        ksort($value);
        foreach ($value as &$item) {
            if (is_array($item)) {
                $item = self::canonical($item);
            }
        }

        return $value;
    }

    private static function minor(mixed $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    private static function sameMoment(mixed $left, mixed $right): bool
    {
        return $left !== null && $right !== null && $left->getTimestamp() === $right->getTimestamp();
    }
}
