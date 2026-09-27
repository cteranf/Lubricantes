<?php

namespace App\Services;

use App\Exceptions\InventoryException;
use App\Models\InventoryMovement;
use App\Models\InventoryReservation;
use App\Models\Order;
use App\Models\PaymentSubmission;
use App\Models\PaymentSubmissionHistory;
use App\Models\PaymentTransaction;
use App\Models\Product;
use App\Models\WarehouseInventory;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

class TreasuryPaymentApprovalService
{
    public function __construct(private InventoryService $inventory, private TreasuryPaymentService $treasury) {}

    public function approve(int $submissionId, int $treasuryUserId): PaymentSubmission
    {
        try {
            return DB::transaction(function () use ($submissionId, $treasuryUserId): PaymentSubmission {
                $submission = PaymentSubmission::query()->lockForUpdate()->find($submissionId);
                if (! $submission) {
                    $this->notFound();
                }

                $order = Order::query()->lockForUpdate()->find($submission->order_id);
                if (! $order) {
                    $this->conflict('El pedido asociado no está disponible.');
                }

                $transactionKey = 'treasury-approval-submission-'.$submission->id;
                $scopeKey = PaymentTransaction::approvedScopeKeyForOrder($order->id);
                $linked = PaymentTransaction::query()->where('payment_submission_id', $submission->id)->lockForUpdate()->first();
                $byKeys = PaymentTransaction::query()->where(fn ($query) => $query->where('idempotency_key', $transactionKey)->orWhere('approved_scope_key', $scopeKey))->lockForUpdate()->first();
                $approvedForOrder = PaymentTransaction::query()->where('order_id', $order->id)->where('transaction_type', PaymentTransaction::PAYMENT)->where('status', PaymentTransaction::APPROVED)->lockForUpdate()->first();
                $reservations = InventoryReservation::query()->where('order_id', $order->id)->orderBy('id')->lockForUpdate()->get();

                if ($submission->status === PaymentSubmission::APPROVED) {
                    if (($byKeys && (! $linked || (int) $byKeys->id !== (int) $linked->id)) || ($approvedForOrder && (! $linked || (int) $approvedForOrder->id !== (int) $linked->id))) {
                        $this->conflict('La aprobación existente es inconsistente y requiere resolución manual.');
                    }
                    if ($this->isConsistentRetry($submission, $order, $linked, $transactionKey, $scopeKey, $reservations)) {
                        return $submission->fresh(['order.reservations', 'reviewer', 'paymentTransaction']);
                    }
                    $this->conflict('La aprobación existente es inconsistente y requiere resolución manual.');
                }
                if ($linked || $byKeys || $approvedForOrder) {
                    $this->conflict('Ya existe una transacción financiera para esta aprobación.');
                }

                $this->assertEligible($submission, $order, $reservations);
                $now = now();
                $transaction = new PaymentTransaction([
                    'order_id' => $order->id,
                    'payment_method' => 'transferencia',
                    'transaction_type' => PaymentTransaction::PAYMENT,
                    'status' => PaymentTransaction::APPROVED,
                    'amount' => (string) $order->total,
                    'currency' => 'PEN',
                    'manual_reference' => $submission->normalized_operation_number,
                    'collection_method' => $submission->channel,
                    'confirmed_by' => $treasuryUserId,
                    'confirmed_at' => $now,
                    'idempotency_key' => $transactionKey,
                    'approved_scope_key' => $scopeKey,
                    'metadata' => ['source' => 'treasury_payment_submission'],
                ]);
                $transaction->payment_submission_id = $submission->id;
                $transaction->save();

                $submission->update(['status' => PaymentSubmission::APPROVED, 'reviewed_by' => $treasuryUserId, 'reviewed_at' => $now, 'decision_reason' => null]);
                PaymentSubmissionHistory::create(['payment_submission_id' => $submission->id, 'event' => 'approved', 'from_status' => PaymentSubmission::PENDING_REVIEW, 'to_status' => PaymentSubmission::APPROVED, 'actor_id' => $treasuryUserId, 'safe_metadata' => $this->treasury->safeHistoryMetadata(['channel' => $submission->channel, 'expected_amount' => (string) $submission->expected_amount, 'currency' => 'PEN']), 'occurred_at' => $now]);
                $order->update(['payment_status' => 'approved', 'paid_at' => $now]);

                try {
                    $this->inventory->consumeOrderReservation($order, null);
                } catch (InventoryException $exception) {
                    $this->inventoryConflict($exception->getMessage());
                }

                return $submission->fresh(['order.reservations', 'reviewer', 'paymentTransaction']);
            });
        } catch (QueryException $exception) {
            if ($this->treasury->isUniqueViolation($exception)) {
                $submission = PaymentSubmission::query()->find($submissionId);
                $order = $submission ? Order::query()->find($submission->order_id) : null;
                $transaction = $submission ? PaymentTransaction::query()->where('payment_submission_id', $submission->id)->first() : null;
                $reservations = $order ? InventoryReservation::query()->where('order_id', $order->id)->orderBy('id')->get() : collect();
                if ($submission && $order && $this->isConsistentRetry(
                    $submission,
                    $order,
                    $transaction,
                    'treasury-approval-submission-'.$submission->id,
                    PaymentTransaction::approvedScopeKeyForOrder($order->id),
                    $reservations
                )) {
                    return $submission->fresh(['order.reservations', 'reviewer', 'paymentTransaction']);
                }
                $this->conflict('La aprobación ya fue registrada; vuelva a consultar el estado.');
            }
            throw $exception;
        }
    }

    private function assertEligible(PaymentSubmission $submission, Order $order, $reservations): void
    {
        if ($submission->status !== PaymentSubmission::PENDING_REVIEW || $order->payment_method !== 'transferencia' || $order->payment_status !== 'pending') {
            $this->conflict('La presentación no permite una aprobación financiera.');
        }
        if ($submission->currency !== 'PEN' || (int) round(((float) $submission->expected_amount) * 100) !== (int) round(((float) $order->total) * 100)) {
            $this->conflict('El importe o la moneda de la presentación son inconsistentes.');
        }
        if (! $order->reserved_until || $order->reserved_until->isPast() || $reservations->isEmpty() || $reservations->contains(fn ($reservation) => $reservation->status !== InventoryReservation::ACTIVE || ! $reservation->expires_at || $reservation->expires_at->isPast())) {
            $this->manualResolution();
        }
        if (in_array($order->status, ['canceled', 'rejected', 'delivered', 'picked_up'], true) || in_array($order->tracking_status, ['delivered', 'picked_up', 'canceled'], true)) {
            $this->conflict('El pedido ya no permite una aprobación financiera.');
        }
        $this->hasConsistentActiveReservations($order, $reservations) || $this->inventoryConflict('Las reservas o el inventario del pedido son inconsistentes.');
    }

    private function isConsistentRetry(PaymentSubmission $submission, Order $order, ?PaymentTransaction $transaction, string $transactionKey, string $scopeKey, $reservations): bool
    {
        return $transaction
            && (int) $transaction->order_id === (int) $order->id
            && (int) round(((float) $submission->expected_amount) * 100) === (int) round(((float) $order->total) * 100)
            && $submission->currency === 'PEN'
            && $transaction->payment_method === 'transferencia'
            && $transaction->transaction_type === PaymentTransaction::PAYMENT
            && $transaction->collection_method === $submission->channel
            && $transaction->status === PaymentTransaction::APPROVED
            && (int) round(((float) $transaction->amount) * 100) === (int) round(((float) $order->total) * 100)
            && $transaction->currency === 'PEN'
            && $transaction->idempotency_key === $transactionKey
            && $transaction->approved_scope_key === $scopeKey
            && $transaction->confirmed_at !== null
            && ! in_array($order->status, ['canceled', 'rejected', 'delivered', 'picked_up'], true)
            && ! in_array($order->tracking_status, ['canceled', 'delivered', 'picked_up'], true)
            && $order->payment_status === 'approved'
            && $order->paid_at !== null
            && $this->hasConsistentConsumedReservations($order, $reservations)
            && $this->hasConsistentSaleMovements($order, $reservations)
            && $this->hasCurrentInventoryInvariants($reservations);
    }

    private function hasConsistentSaleMovements(Order $order, $reservations): bool
    {
        foreach ($reservations as $reservation) {
            $movements = InventoryMovement::query()->where('reference_type', 'order')->where('reference_id', (string) $order->id)->where('warehouse_id', $reservation->warehouse_id)->where('product_id', $reservation->product_id)->where('type', InventoryMovement::SALE)->where('quantity', $reservation->quantity)->get();
            if ($movements->count() !== 1 || $movements->first()->idempotency_key !== 'sale-order-item-'.$reservation->order_item_id || (int) $movements->first()->quantity_before - (int) $movements->first()->quantity_after !== (int) $reservation->quantity) {
                return false;
            }
        }

        return true;
    }

    private function hasCurrentInventoryInvariants($reservations): bool
    {
        foreach ($reservations->pluck('product_id')->unique() as $productId) {
            $product = Product::query()->find($productId);
            if (! $product || (int) $product->stock !== (int) WarehouseInventory::query()->where('product_id', $productId)->sum('quantity')) {
                return false;
            }
        }

        return ! WarehouseInventory::query()->whereIn('warehouse_id', $reservations->pluck('warehouse_id'))->whereIn('product_id', $reservations->pluck('product_id'))->where(fn ($query) => $query->where('quantity', '<', 0)->orWhere('reserved_quantity', '<', 0))->exists();
    }

    private function hasConsistentActiveReservations(Order $order, $reservations): bool
    {
        return $this->hasReservationStructure($order, $reservations, InventoryReservation::ACTIVE, true);
    }

    private function hasConsistentConsumedReservations(Order $order, $reservations): bool
    {
        return $this->hasReservationStructure($order, $reservations, InventoryReservation::CONSUMED, false)
            && $reservations->every(fn ($reservation) => $reservation->consumed_at !== null);
    }

    private function hasReservationStructure(Order $order, $reservations, string $status, bool $validateInventory): bool
    {
        $items = $order->items()->get()->keyBy('id');
        if ($reservations->isEmpty() || $reservations->count() !== $items->count() || $reservations->pluck('order_item_id')->unique()->count() !== $items->count()) {
            return false;
        }
        foreach ($reservations as $reservation) {
            $item = $items->get($reservation->order_item_id);
            if (! $item || $reservation->status !== $status || (int) $reservation->product_id !== (int) $item->product_id || (int) $reservation->warehouse_id !== (int) $item->warehouse_id || (int) $reservation->quantity !== (int) $item->quantity) {
                return false;
            }
            if ($validateInventory) {
                $inventory = WarehouseInventory::query()->where('warehouse_id', $reservation->warehouse_id)->where('product_id', $reservation->product_id)->first();
                if (! $inventory || (int) $inventory->quantity < (int) $reservation->quantity || (int) $inventory->reserved_quantity < (int) $reservation->quantity || (int) $inventory->quantity < 0 || (int) $inventory->reserved_quantity < 0) {
                    return false;
                }
            }
        }

        return $this->hasCurrentInventoryInvariants($reservations);
    }

    private function conflict(string $message): never
    {
        throw new HttpResponseException(response()->json(['message' => $message, 'code' => 'payment_submission_approval_conflict'], 409)
            ->header('Cache-Control', 'no-store, private')->header('Pragma', 'no-cache'));
    }

    private function manualResolution(): never
    {
        throw new HttpResponseException(response()->json(['message' => 'El pago requiere resolución manual porque la reserva ya no está vigente.', 'code' => 'manual_resolution_required'], 409)
            ->header('Cache-Control', 'no-store, private')->header('Pragma', 'no-cache'));
    }

    private function inventoryConflict(string $message): never
    {
        throw new HttpResponseException(response()->json(['message' => $message, 'code' => 'payment_inventory_conflict'], 409)
            ->header('Cache-Control', 'no-store, private')->header('Pragma', 'no-cache'));
    }

    private function notFound(): never
    {
        throw new HttpResponseException(response()->json(['message' => 'La presentación de pago no existe.', 'code' => 'payment_submission_not_found'], 404)
            ->header('Cache-Control', 'no-store, private')->header('Pragma', 'no-cache'));
    }
}
