<?php

namespace App\Services;

use App\Models\InventoryReservation;
use App\Models\Order;
use App\Models\OrderFulfillmentHistory;
use App\Models\WarehouseInventory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class InventoryReservationExpirationService
{
    public function expireDueReservations(?int $orderId = null, ?int $warehouseId = null, array $productIds = [], int $limit = 100): array
    {
        $query = Order::query()
            ->where(function ($query) {
                $query->whereNotNull('reserved_until')->where('reserved_until', '<=', now())
                    ->orWhereHas('reservations', fn ($reservations) => $reservations->where('status', InventoryReservation::ACTIVE)->where('expires_at', '<=', now()));
            })
            ->whereHas('reservations', function ($reservations) use ($warehouseId, $productIds) {
                $reservations->where('status', InventoryReservation::ACTIVE)
                    ->when($warehouseId, fn ($query) => $query->where('warehouse_id', $warehouseId))
                    ->when($productIds, fn ($query) => $query->whereIn('product_id', $productIds));
            })
            ->orderBy('id');

        if ($orderId !== null) {
            $query->whereKey($orderId);
        }

        $result = ['processed' => 0, 'skipped' => 0, 'failed' => 0, 'orders' => []];
        $query->limit(max(1, min(1000, $limit)))->get()->each(function (Order $order) use (&$result, $warehouseId, $productIds) {
            try {
                $classification = $this->classify($order);
                if (! $classification['processable']) {
                    $result['skipped']++;
                    $result['orders'][] = ['order_id' => $order->id, 'processed' => false] + $classification;

                    return;
                }
                $outcome = $this->expireOrder($order->id, $warehouseId, $productIds);
                $outcome['reason'] = $classification['reason'];
                $result[$outcome['processed'] ? 'processed' : 'skipped']++;
                $result['orders'][] = $outcome;
            } catch (\Throwable $exception) {
                $result['failed']++;
                $result['orders'][] = ['order_id' => $order->id, 'processed' => false, 'error' => $exception->getMessage()];
            }
        });

        return $result;
    }

    public function expireOrder(int $orderId, ?int $warehouseId = null, array $productIds = []): array
    {
        return DB::transaction(function () use ($orderId, $warehouseId, $productIds) {
            $order = Order::whereKey($orderId)->lockForUpdate()->firstOrFail();
            $reservations = InventoryReservation::where('order_id', $order->id)
                ->where('status', InventoryReservation::ACTIVE)
                ->when($warehouseId, fn ($query) => $query->where('warehouse_id', $warehouseId))
                ->when($productIds, fn ($query) => $query->whereIn('product_id', $productIds))
                ->orderBy('warehouse_id')->orderBy('product_id')->orderBy('id')
                ->lockForUpdate()->get();

            $base = ['order_id' => $order->id, 'processed' => false, 'reservations' => [], 'products' => []];
            if ($reservations->isEmpty()) {
                return $base;
            }
            $classification = $this->classify($order);
            if (! $classification['processable']) {
                return array_merge($base, $classification);
            }
            if (! $this->isDue($order, $reservations)) {
                return array_merge($base, ['reason' => 'ambiguous_legacy_order']);
            }

            $inventoryKeys = $reservations->map(fn (InventoryReservation $reservation) => $reservation->warehouse_id.':'.$reservation->product_id)->unique();
            $inventories = $this->lockInventories($inventoryKeys);
            $released = [];

            foreach ($reservations as $reservation) {
                $key = $reservation->warehouse_id.':'.$reservation->product_id;
                $inventory = $inventories->get($key);
                $before = (int) $inventory->reserved_quantity;
                $inventory->update(['reserved_quantity' => max(0, $before - (int) $reservation->quantity)]);
                $reservation->update([
                    'status' => InventoryReservation::EXPIRED,
                    'released_at' => now(),
                    'metadata' => array_merge($reservation->metadata ?? [], ['release_reason' => 'reservation_expired']),
                ]);
                $released[] = ['reservation_id' => $reservation->id, 'product_id' => $reservation->product_id, 'quantity' => (int) $reservation->quantity];
            }

            if ($order->status === 'pending') {
                $order->update(['status' => 'canceled', 'tracking_status' => 'canceled', 'fulfillment_status' => Order::FULFILLMENT_CANCELED]);
            } elseif ($order->effectiveFulfillmentStatus() !== Order::FULFILLMENT_CANCELED) {
                $order->update(['tracking_status' => 'canceled', 'fulfillment_status' => Order::FULFILLMENT_CANCELED]);
            }

            OrderFulfillmentHistory::firstOrCreate([
                'idempotency_key' => 'reservation-expired-order-'.$order->id,
            ], [
                'order_id' => $order->id,
                'from_status' => Order::FULFILLMENT_RESERVED,
                'to_status' => Order::FULFILLMENT_CANCELED,
                'user_id' => null,
                'observation' => 'Reserva de inventario expirada automáticamente.',
                'metadata' => ['reservation_ids' => array_column($released, 'reservation_id')],
                'created_at' => now(),
            ]);

            return array_merge($base, ['processed' => true, 'reason' => $classification['reason'], 'reservations' => $released, 'products' => $this->summarize($released)]);
        });
    }

    public function previewDueReservations(?int $orderId = null, ?int $warehouseId = null, array $productIds = [], int $limit = 100): Collection
    {
        return InventoryReservation::query()->with('order:id,status,payment_status,paid_at,reserved_until,payment_method,payment_data')
            ->where('status', InventoryReservation::ACTIVE)->whereHas('order', function ($orders) use ($orderId) {
                $orders->where(function ($query) {
                    $query->whereNotNull('reserved_until')->where('reserved_until', '<=', now())->orWhereHas('reservations', fn ($reservations) => $reservations->where('status', InventoryReservation::ACTIVE)->where('expires_at', '<=', now()));
                })->when($orderId, fn ($query) => $query->whereKey($orderId));
            })->when($warehouseId, fn ($query) => $query->where('warehouse_id', $warehouseId))->when($productIds, fn ($query) => $query->whereIn('product_id', $productIds))->orderBy('id')->limit(max(1, min(1000, $limit)))->get();
    }

    public function classify(Order $order): array
    {
        if ($order->payment_status === 'approved' || $order->paid_at) {
            return ['processable' => false, 'reason' => 'paid_order_excluded'];
        }
        if (in_array($order->payment_method, ['contra_entrega', 'pago_en_sede'], true)) {
            return ['processable' => false, 'reason' => 'offline_payment_excluded'];
        }
        if (! in_array($order->payment_method, ['card', 'transferencia'], true) || ! $order->reserved_until) {
            return ['processable' => false, 'reason' => 'ambiguous_legacy_order', 'manual_review' => true];
        }
        if ($order->payment_method === 'transferencia' && $this->transferProofPending($order)) {
            return ['processable' => false, 'reason' => 'ambiguous_legacy_order', 'manual_review' => true];
        }
        if ($order->payment_method === 'transferencia') {
            return ['processable' => true, 'reason' => 'expired_transfer_without_proof'];
        }

        return ['processable' => true, 'reason' => 'expired_online_payment'];
    }

    private function transferProofPending(Order $order): bool
    {
        $data = $order->payment_data ?? [];
        $status = strtolower((string) ($data['transfer_proof_status'] ?? $data['proof_status'] ?? $data['comprobante_status'] ?? ''));
        if (in_array($status, ['submitted', 'pending', 'under_review', 'received', 'en_revision', 'pendiente'], true)) {
            return true;
        }

        return (bool) array_intersect(array_keys($data), ['transfer_proof', 'proof_url', 'receipt_url', 'voucher_url', 'comprobante_url']);
    }

    private function isDue(Order $order, Collection $reservations): bool
    {
        $orderDue = $order->reserved_until !== null && $order->reserved_until->isPast();
        $legacyReservationDue = $reservations->every(fn (InventoryReservation $reservation) => $reservation->expires_at !== null && $reservation->expires_at->isPast());

        return ($orderDue || $legacyReservationDue) && in_array($order->status, ['pending', 'canceled', 'rejected'], true);
    }

    private function lockInventories(Collection $keys): Collection
    {
        $pairs = $keys->map(fn (string $key) => array_map('intval', explode(':', $key)))->sortBy(fn (array $pair) => $pair[0].':'.$pair[1]);
        $locked = collect();
        foreach ($pairs as [$warehouseId, $productId]) {
            $inventory = WarehouseInventory::where('warehouse_id', $warehouseId)->where('product_id', $productId)->lockForUpdate()->firstOrFail();
            $locked->put($warehouseId.':'.$productId, $inventory);
        }

        return $locked;
    }

    private function summarize(array $released): array
    {
        return collect($released)->groupBy('product_id')->map(fn ($items, $productId) => ['product_id' => (int) $productId, 'quantity' => $items->sum('quantity')])->values()->all();
    }
}
