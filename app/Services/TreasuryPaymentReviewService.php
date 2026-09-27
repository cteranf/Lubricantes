<?php

namespace App\Services;

use App\Models\InventoryReservation;
use App\Models\Order;
use App\Models\PaymentSubmission;
use App\Models\PaymentSubmissionHistory;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

class TreasuryPaymentReviewService
{
    public function __construct(private TreasuryPaymentService $treasury) {}

    public function observe(int $submissionId, int $actorId, string $reason): PaymentSubmission
    {
        return DB::transaction(function () use ($submissionId, $actorId, $reason): PaymentSubmission {
            $submission = PaymentSubmission::query()->lockForUpdate()->find($submissionId);
            if (! $submission) {
                $this->notFound();
            }
            if (! $this->treasury->canTransition($submission->status, PaymentSubmission::OBSERVED)) {
                $this->conflict('La presentación ya no permite esta decisión.');
            }
            $order = Order::query()->lockForUpdate()->find($submission->order_id);
            $reservations = InventoryReservation::query()->where('order_id', $order->id)->where('status', InventoryReservation::ACTIVE)->lockForUpdate()->get();
            if (! $order->reserved_until || $order->reserved_until->isPast() || $reservations->isEmpty() || $reservations->contains(fn ($r) => $r->expires_at->isPast())) {
                $this->conflict('La reserva de este pedido no está vigente.');
            }
            $now = now();
            $expiry = $now->copy()->addMinutes(config('treasury.observation_correction_minutes'));
            foreach ($reservations as $r) {
                if ($r->expires_at->greaterThan($expiry)) {
                    $expiry = $r->expires_at->copy();
                }
            }
            if ($order->reserved_until->greaterThan($expiry)) {
                $expiry = $order->reserved_until->copy();
            }
            $submission->update(['status' => PaymentSubmission::OBSERVED, 'reviewed_by' => $actorId, 'reviewed_at' => $now, 'decision_reason' => $reason]);
            PaymentSubmissionHistory::create(['payment_submission_id' => $submission->id, 'event' => 'observed', 'from_status' => PaymentSubmission::PENDING_REVIEW, 'to_status' => PaymentSubmission::OBSERVED, 'actor_id' => $actorId, 'reason' => $reason, 'safe_metadata' => ['previous_expires_at' => $order->reserved_until->toIso8601String(), 'new_expires_at' => $expiry->toIso8601String(), 'configured_minutes' => config('treasury.observation_correction_minutes')], 'occurred_at' => $now]);
            $order->update(['reserved_until' => $expiry]);
            InventoryReservation::query()->whereIn('id', $reservations->filter(fn ($r) => $r->expires_at->isFuture())->pluck('id'))->update(['expires_at' => $expiry]);

            return $submission->fresh(['order.user', 'order.reservations', 'reviewer', 'histories.actor']);
        });
    }

    public function reject(int $submissionId, int $actorId, string $reason): PaymentSubmission
    {
        return $this->transition($submissionId, $actorId, PaymentSubmission::REJECTED, $reason, 'rejected');
    }

    private function transition(int $submissionId, int $actorId, string $to, string $reason, string $event): PaymentSubmission
    {
        return DB::transaction(function () use ($submissionId, $actorId, $to, $reason, $event): PaymentSubmission {
            $submission = PaymentSubmission::query()->lockForUpdate()->find($submissionId);
            if (! $submission) {
                $this->notFound();
            }
            if (! $this->treasury->canTransition($submission->status, $to)) {
                $this->conflict('La presentación ya no permite esta decisión.');
            }

            $from = $submission->status;
            $now = now();
            $submission->update([
                'status' => $to,
                'reviewed_by' => $actorId,
                'reviewed_at' => $now,
                'decision_reason' => $reason,
            ]);
            PaymentSubmissionHistory::create([
                'payment_submission_id' => $submission->id,
                'event' => $event,
                'from_status' => $from,
                'to_status' => $to,
                'actor_id' => $actorId,
                'reason' => $reason,
                'occurred_at' => $now,
            ]);

            return $submission->fresh(['order.user', 'order.reservations', 'reviewer', 'histories.actor']);
        });
    }

    private function conflict(string $message): never
    {
        throw new HttpResponseException(response()->json(['message' => $message, 'code' => 'payment_submission_transition_conflict'], 409));
    }

    private function notFound(): never
    {
        throw new HttpResponseException(response()->json(['message' => 'La presentación de pago no existe.'], 404));
    }
}
