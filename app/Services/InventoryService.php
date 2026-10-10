<?php

namespace App\Services;

use App\Exceptions\InsufficientVersionedInventoryStockException;
use App\Exceptions\InventoryException;
use App\Exceptions\InventoryReservationConflictException;
use App\Models\InventoryMovement;
use App\Models\InventoryReservation;
use App\Models\Order;
use App\Models\OrderFulfillmentHistory;
use App\Models\OrderItem;
use App\Models\PaymentResolutionCase;
use App\Models\PaymentSubmission;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseInventory;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LogicException;

class InventoryService
{
    public function __construct(
        private InventoryReservationExpirationService $reservationExpiration,
        private InventoryBalanceLockService $balanceLocks
    ) {}

    public function defaultWarehouse(): Warehouse
    {
        $warehouse = Warehouse::where('is_active', true)->where('is_default', true)
            ->whereHas('branch', fn ($q) => $q->where('is_active', true))
            ->orderByRaw('CASE WHEN code = ? THEN 0 ELSE 1 END', [Warehouse::INITIAL_CODE])->first();
        if (! $warehouse) {
            throw new InventoryException('No existe un almacén principal activo configurado.');
        }

        return $warehouse;
    }

    public function stockTotal(Product $product): int
    {
        return Schema::hasTable('warehouse_inventories') ? (int) $product->inventories()->sum('quantity') : (int) $product->stock;
    }

    public function sellableStock(Product $product): int
    {
        if (! Schema::hasTable('warehouse_inventories')) {
            return (int) $product->stock;
        }

        return $this->availableQuantity($product, $this->defaultWarehouse());
    }

    public function availableQuantity(Product $product, ?Warehouse $warehouse = null): int
    {
        if (! Schema::hasTable('warehouse_inventories')) {
            return (int) $product->stock;
        }
        $warehouse = $warehouse ?: $this->defaultWarehouse();
        $this->reservationExpiration->expireDueReservations(null, $warehouse->id, [$product->id]);
        $inventory = WarehouseInventory::where('warehouse_id', $warehouse->id)
            ->where('product_id', $product->id)->first();

        return $inventory ? max(0, (int) $inventory->quantity - (int) $inventory->reserved_quantity) : 0;
    }

    public function reserveForOrder(OrderItem $item, \DateTimeInterface $expiresAt): InventoryReservation
    {
        $warehouse = $item->warehouse ?: $this->defaultWarehouse();
        $this->reservationExpiration->expireDueReservations(null, $warehouse->id, [$item->product_id]);

        return DB::transaction(function () use ($item, $expiresAt) {
            $warehouse = $item->warehouse ?: $this->defaultWarehouse();
            if (! $item->warehouse_id) {
                $item->update(['warehouse_id' => $warehouse->id]);
            }
            $context = $this->lockProductInventoryContext($item->product, [$warehouse->id]);
            $inventory = $this->lockedBalance($context['inventories'], $warehouse->id, $item->product_id);
            $key = 'reservation-order-item-'.$item->id;
            if ($existing = InventoryReservation::where('idempotency_key', $key)->first()) {
                return $existing;
            }
            if ($inventory->available_quantity < $item->quantity) {
                throw new InventoryException("No hay stock disponible suficiente para {$item->product->name}.");
            }
            $reservation = InventoryReservation::create([
                'order_id' => $item->order_id, 'order_item_id' => $item->id, 'product_id' => $item->product_id,
                'warehouse_id' => $warehouse->id, 'quantity' => $item->quantity, 'status' => InventoryReservation::ACTIVE,
                'expires_at' => $expiresAt, 'idempotency_key' => $key,
            ]);
            $inventory->update(['reserved_quantity' => (int) $inventory->reserved_quantity + $item->quantity]);
            $this->syncProductStockFromLockedBalances($context['product'], $context['inventories']);

            return $reservation;
        });
    }

    public function releaseOrderReservation(Order $order, string $status = InventoryReservation::RELEASED): array
    {
        if (! in_array($status, [InventoryReservation::RELEASED, InventoryReservation::EXPIRED], true)) {
            throw new InventoryException('Estado de liberacion de reserva invalido.');
        }

        return DB::transaction(function () use ($order, $status) {
            Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $processed = 0;
            $skipped = 0;
            $reservations = InventoryReservation::where('order_id', $order->id)->orderBy('id')->lockForUpdate()->get();
            $activeReservations = $reservations->where('status', InventoryReservation::ACTIVE);
            $inventories = $this->balanceLocks->lock($activeReservations->map(fn (InventoryReservation $reservation) => [
                'warehouse_id' => (int) $reservation->warehouse_id,
                'product_id' => (int) $reservation->product_id,
            ]));
            foreach ($reservations as $reservation) {
                if ($reservation->status !== InventoryReservation::ACTIVE) {
                    $skipped++;

                    continue;
                }
                $inventory = $inventories->get($this->balanceLocks->key((int) $reservation->warehouse_id, (int) $reservation->product_id));
                if (! $inventory instanceof WarehouseInventory) {
                    throw new InventoryException('No se obtuvo el saldo bloqueado de la reserva.');
                }
                if ($inventory->reserved_quantity < $reservation->quantity) {
                    throw new InventoryException('El saldo reservado es inconsistente.');
                }
                $inventory->update(['reserved_quantity' => $inventory->reserved_quantity - $reservation->quantity]);
                $reservation->update(['status' => $status, 'released_at' => now(), 'metadata' => array_merge($reservation->metadata ?? [], ['release_reason' => $status === InventoryReservation::EXPIRED ? 'reservation_expired' : 'order_canceled'])]);
                $processed++;
            }

            return compact('processed', 'skipped');
        });
    }

    public function consumeOrderReservation(Order $order, ?User $user = null): array
    {
        return DB::transaction(function () use ($order, $user) {
            Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $processed = 0;
            $skipped = 0;
            $reservations = InventoryReservation::where('order_id', $order->id)->orderBy('id')->lockForUpdate()->get();
            if ($reservations->count() !== $order->items()->count()) {
                throw new InventoryException('El pedido no tiene todas sus reservas de inventario.');
            }
            $productIds = $reservations->pluck('product_id')->map(fn ($id) => (int) $id)->unique()->sort()->values();
            $warehouseIds = $reservations->pluck('warehouse_id')->map(fn ($id) => (int) $id)->unique()->sort()->values();
            $lockedWarehouses = Warehouse::query()->whereIn('id', $warehouseIds)->orderBy('id')->lockForUpdate()->get();
            if ($lockedWarehouses->count() !== $warehouseIds->count()) {
                throw new InventoryException('Un almacén reservado ya no existe.');
            }
            $allProductPairs = WarehouseInventory::query()
                ->whereIn('product_id', $productIds)
                ->get(['warehouse_id', 'product_id'])
                ->map(fn (WarehouseInventory $inventory) => [
                    'warehouse_id' => (int) $inventory->warehouse_id,
                    'product_id' => (int) $inventory->product_id,
                ]);
            $requiredPairs = $reservations->map(fn (InventoryReservation $reservation) => [
                'warehouse_id' => (int) $reservation->warehouse_id,
                'product_id' => (int) $reservation->product_id,
            ]);
            $inventories = $this->balanceLocks->lock($allProductPairs->concat($requiredPairs));
            $products = Product::query()->whereIn('id', $productIds)->orderBy('id')->lockForUpdate()->get();
            if ($products->count() !== $productIds->count()) {
                throw new InventoryException('Un producto reservado ya no existe.');
            }
            foreach ($reservations as $reservation) {
                if ($reservation->status === InventoryReservation::CONSUMED) {
                    $skipped++;

                    continue;
                }
                if ($reservation->status !== InventoryReservation::ACTIVE) {
                    throw new InventoryException('La reserva ya no esta activa y no puede consumirse.');
                }
                $inventory = $inventories->get($this->balanceLocks->key((int) $reservation->warehouse_id, (int) $reservation->product_id));
                if ($inventory->reserved_quantity < $reservation->quantity || $inventory->quantity < $reservation->quantity) {
                    throw new InventoryException('El inventario reservado es inconsistente.');
                }
                $before = (int) $inventory->quantity;
                $after = $before - $reservation->quantity;
                $inventory->update(['quantity' => $after, 'reserved_quantity' => $inventory->reserved_quantity - $reservation->quantity]);
                $reservation->update(['status' => InventoryReservation::CONSUMED, 'consumed_at' => now()]);
                InventoryMovement::firstOrCreate(['idempotency_key' => 'sale-order-item-'.$reservation->order_item_id], [
                    'warehouse_id' => $reservation->warehouse_id, 'product_id' => $reservation->product_id, 'user_id' => $user?->id,
                    'type' => InventoryMovement::SALE, 'quantity' => $reservation->quantity, 'quantity_before' => $before, 'quantity_after' => $after,
                    'reason' => 'Venta por pedido', 'reference_type' => 'order', 'reference_id' => (string) $order->id, 'metadata' => [], 'created_at' => now(),
                ]);
                $processed++;
            }
            foreach ($products as $product) {
                $total = (int) $inventories->where('product_id', $product->id)->sum('quantity');
                Product::whereKey($product->id)->update(['stock' => $total]);
            }

            return compact('processed', 'skipped');
        });
    }

    /**
     * Creates one active, versioned reservation per item for a resolution case.
     *
     * This is intentionally an internal primitive: callers must already own an
     * outer transaction that governs their financial resolution workflow.
     */
    public function reserveVersionedForResolutionCase(Order $order, PaymentResolutionCase $case, \DateTimeInterface $expiresAt, ?User $actor = null): array
    {
        return $this->reserveVersionedForResolutionCaseWithLockedEvidence($order, $case, $expiresAt, $actor)['reservations'];
    }

    /**
     * Creates the versioned reservations and returns the locked rows used to
     * evaluate the original reservation provenance in the outer transaction.
     *
     * @return array{reservations: array<int, InventoryReservation>, items: Collection, historical_reservations: Collection, fulfillment_histories: Collection}
     */
    public function reserveVersionedForResolutionCaseWithLockedEvidence(Order $order, PaymentResolutionCase $case, \DateTimeInterface $expiresAt, ?User $actor = null): array
    {
        $this->requireOuterTransaction();

        try {
            return DB::transaction(fn () => $this->reserveVersionedForResolutionCaseWithinSavepoint($order, $case, $expiresAt));
        } catch (QueryException $exception) {
            if (! app(TreasuryPaymentService::class)->isUniqueViolation($exception)) {
                throw $exception;
            }

            $context = $this->lockResolutionReservationContext($order, $case);
            $reservations = $this->coherentExistingCaseReservations($context);
            if ($reservations !== null) {
                return $this->reservationResultWithLockedEvidence($reservations, $context);
            }

            $this->reservationConflict('La reserva versionada existente es inconsistente.');
        }
    }

    /**
     * Consumes only the active reservation versions attached to this case.
     * Historical order reservations are deliberately excluded.
     */
    public function consumeVersionedReservationsForResolutionCase(Order $order, PaymentResolutionCase $case, ?User $user = null): array
    {
        $this->requireOuterTransaction();

        return DB::transaction(fn () => $this->consumeVersionedReservationsForResolutionCaseWithinSavepoint($order, $case, $user));
    }

    /**
     * Validates a completed reassignment without mutating it.
     *
     * @return array<int, InventoryReservation>
     */
    public function assertCoherentConsumedReservationsForResolutionCase(Order $order, PaymentResolutionCase $case): array
    {
        $this->requireOuterTransaction();

        return DB::transaction(function () use ($order, $case): array {
            $context = $this->lockResolutionReservationContext(
                $order,
                $case,
                [PaymentResolutionCase::REASSIGNED],
                [PaymentResolutionCase::STOCK_REASSIGNMENT]
            );
            $reservations = $context['historicalReservations']
                ->where('resolution_case_id', $context['case']->id)
                ->sortBy('id')
                ->values();
            $this->assertCompleteCaseReservationSet($context['items'], $reservations, $context['case']->id);
            if (! $reservations->every(fn (InventoryReservation $reservation) => $reservation->status === InventoryReservation::CONSUMED)) {
                $this->reservationConflict('Las reservas resueltas no están completamente consumidas.');
            }
            $this->assertCoherentConsumedCaseReservations($context['order'], $reservations, $context['inventories']);

            return $reservations->all();
        });
    }

    public function expireOrderReservation(InventoryReservation $reservation): bool
    {
        return $this->reservationExpiration->expireOrder($reservation->order_id)['processed'];
    }

    public function orderHasConsumedReservation(Order $order): bool
    {
        return InventoryReservation::where('order_id', $order->id)->where('status', InventoryReservation::CONSUMED)->exists();
    }

    public function orderUsesReservationFlow(Order $order): bool
    {
        return $order->reserved_until !== null || InventoryReservation::where('order_id', $order->id)->exists();
    }

    public function initializeProduct(Product $product, int $quantity, ?Warehouse $warehouse = null, ?User $user = null): ?InventoryMovement
    {
        if ($quantity < 0) {
            throw new InventoryException('El stock inicial no puede ser negativo.');
        }
        if (! Schema::hasTable('warehouse_inventories')) {
            return DB::transaction(function () use ($product, $quantity) {
                Product::whereKey($product->id)->lockForUpdate()->update(['stock' => $quantity]);

                return null;
            });
        }
        if ($quantity === 0) {
            return null;
        }
        if (! $warehouse) {
            throw new InventoryException('Debe seleccionar un almacén para el stock inicial.');
        }

        return $this->change($product, $warehouse, $quantity, InventoryMovement::INITIAL, 'Stock inicial del producto', $user, [], 'product', (string) $product->id, 'initial-product-'.$product->id);
    }

    public function manualIn(Product $product, Warehouse $warehouse, int $quantity, string $reason, ?User $user = null, ?string $notes = null): InventoryMovement
    {
        return $this->change($product, $warehouse, $quantity, InventoryMovement::MANUAL_IN, $reason, $user, ['notes' => $notes]);
    }

    public function manualOut(Product $product, Warehouse $warehouse, int $quantity, string $reason, ?User $user = null, ?string $notes = null): InventoryMovement
    {
        return $this->change($product, $warehouse, -$quantity, InventoryMovement::MANUAL_OUT, $reason, $user, ['notes' => $notes]);
    }

    public function adjust(Product $product, Warehouse $warehouse, int $targetQuantity, string $reason, ?User $user = null, ?string $notes = null): InventoryMovement
    {
        if ($targetQuantity < 0) {
            throw new InventoryException('El saldo ajustado no puede ser negativo.');
        }

        return DB::transaction(function () use ($product, $warehouse, $targetQuantity, $reason, $user, $notes) {
            $context = $this->lockProductInventoryContext($product, [$warehouse->id], [$warehouse->id]);
            $inventory = $this->lockedBalance($context['inventories'], $warehouse->id, $product->id);
            if ($targetQuantity < $inventory->reserved_quantity) {
                throw new InventoryException('El saldo fisico no puede quedar por debajo del stock reservado.');
            }
            $delta = $targetQuantity - $inventory->quantity;
            if ($delta === 0) {
                throw new InventoryException('El saldo contado coincide con el saldo registrado; no se creó ningún movimiento.');
            }

            $movement = $this->applyLocked($inventory, $delta, InventoryMovement::CORRECTION, $reason, $user, null, null, null, ['notes' => $notes, 'counted_quantity' => $targetQuantity, 'difference' => $delta, 'direction' => $delta > 0 ? 'increase' : 'decrease']);
            $this->syncProductStockFromLockedBalances($context['product'], $context['inventories']);

            return $movement;
        });
    }

    public function transfer(Product $product, Warehouse $source, Warehouse $destination, int $quantity, string $reason, ?User $user = null, ?string $notes = null): array
    {
        $this->positive($quantity);
        if ($source->is($destination)) {
            throw new InventoryException('El almacén de destino debe ser diferente al de origen.');
        }

        $productId = (int) $product->getKey();
        $sourceWarehouseId = (int) $source->getKey();
        $destinationWarehouseId = (int) $destination->getKey();
        $actorId = $user?->getKey();

        return DB::transaction(function () use ($productId, $sourceWarehouseId, $destinationWarehouseId, $quantity, $reason, $actorId, $notes) {
            $product = Product::query()->findOrFail($productId);
            $source = Warehouse::query()->findOrFail($sourceWarehouseId);
            $destination = Warehouse::query()->findOrFail($destinationWarehouseId);
            $actor = $actorId === null ? null : User::query()->findOrFail($actorId);
            if ($actor && ! $actor->is_active) {
                throw new InventoryException('El actor de la transferencia ya no está activo.');
            }
            $context = $this->lockProductInventoryContext(
                $product,
                [$sourceWarehouseId, $destinationWarehouseId],
                [$destinationWarehouseId]
            );
            if (! $context['product']->is_active) {
                throw new InventoryException('El producto de la transferencia ya no está activo.');
            }
            $sourceInventory = $this->lockedBalance($context['inventories'], $sourceWarehouseId, $productId);
            $destinationInventory = $this->lockedBalance($context['inventories'], $destinationWarehouseId, $productId);
            $this->afterTransferAttemptLocked($context['product'], $source, $destination, $actor, $context['inventories']);
            $id = (string) Str::uuid();
            $meta = ['notes' => $notes, 'source_warehouse_id' => $sourceWarehouseId, 'destination_warehouse_id' => $destinationWarehouseId];
            $out = $this->applyLocked($sourceInventory, -$quantity, InventoryMovement::TRANSFER_OUT, $reason, $actor, 'inventory_transfer', $id, 'transfer-'.$id.'-out', $meta);
            $in = $this->applyLocked($destinationInventory, $quantity, InventoryMovement::TRANSFER_IN, $reason, $actor, 'inventory_transfer', $id, 'transfer-'.$id.'-in', $meta);
            $this->syncProductStockFromLockedBalances($context['product'], $context['inventories']);

            return [$out, $in];
        }, 3);
    }

    /**
     * Test seam executed after every transfer attempt has reloaded and locked its context.
     * Production intentionally performs no additional work here.
     */
    protected function afterTransferAttemptLocked(Product $product, Warehouse $source, Warehouse $destination, ?User $actor, Collection $inventories): void
    {
        // Intentionally empty.
    }

    public function sell(OrderItem $item, ?User $user = null): ?InventoryMovement
    {
        return DB::transaction(function () use ($item, $user) {
            if (! Schema::hasTable('warehouse_inventories')) {
                $product = Product::whereKey($item->product_id)->lockForUpdate()->firstOrFail();
                if ($product->stock < $item->quantity) {
                    throw new InventoryException("No hay stock suficiente para {$product->name}.");
                }
                $product->decrement('stock', $item->quantity);

                return null;
            }
            $warehouse = $item->warehouse ?: $this->defaultWarehouse();
            $item->update(['warehouse_id' => $warehouse->id]);

            return $this->change($item->product, $warehouse, -$item->quantity, InventoryMovement::SALE, 'Venta por pedido', $user, [], 'order', (string) $item->order_id, 'sale-order-item-'.$item->id);
        });
    }

    public function returnCancellation(OrderItem $item, ?User $user = null): ?InventoryMovement
    {
        return DB::transaction(function () use ($item, $user) {
            if (! Schema::hasTable('warehouse_inventories')) {
                optional($item->product)->increment('stock', $item->quantity);

                return null;
            }
            $key = 'cancellation-order-item-'.$item->id;
            $warehouse = $item->warehouse ?: $this->defaultWarehouse();

            return $this->change($item->product, $warehouse, $item->quantity, InventoryMovement::CANCELLATION_RETURN, 'Reposición por cancelación de pedido', $user, [], 'order', (string) $item->order_id, $key);
        });
    }

    /**
     * Restores every consumed item of an order after acquiring all shared rows
     * in the global inventory lock order. This prevents multi-item cancellation
     * from acquiring balance rows according to reservation insertion order.
     *
     * @return array<int, InventoryMovement>
     */
    public function returnOrderCancellation(Order $order, ?User $user = null): array
    {
        return DB::transaction(function () use ($order, $user): array {
            $items = OrderItem::query()
                ->where('order_id', $order->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $consumedReservations = InventoryReservation::query()
                ->where('order_id', $order->id)
                ->where('status', InventoryReservation::CONSUMED)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $itemsToReturn = $this->orderUsesReservationFlow($order)
                ? $items->whereIn('id', $consumedReservations->pluck('order_item_id'))->values()
                : $items;
            if ($itemsToReturn->isEmpty()) {
                return [];
            }

            $warehouseIds = $itemsToReturn->pluck('warehouse_id')->map(fn ($id) => (int) $id)->unique()->sort()->values();
            $warehouses = Warehouse::query()
                ->with('branch')
                ->whereIn('id', $warehouseIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            if ($warehouses->count() !== $warehouseIds->count()) {
                throw new InventoryException('Un almacén del pedido ya no existe.');
            }
            foreach ($warehouses as $warehouse) {
                $this->assertActive($warehouse);
            }

            $productIds = $itemsToReturn->pluck('product_id')->map(fn ($id) => (int) $id)->unique()->sort()->values();
            $allProductPairs = WarehouseInventory::query()
                ->whereIn('product_id', $productIds)
                ->get(['warehouse_id', 'product_id'])
                ->map(fn (WarehouseInventory $inventory) => [
                    'warehouse_id' => (int) $inventory->warehouse_id,
                    'product_id' => (int) $inventory->product_id,
                ]);
            $requiredPairs = $itemsToReturn->map(fn (OrderItem $item) => [
                'warehouse_id' => (int) $item->warehouse_id,
                'product_id' => (int) $item->product_id,
            ]);
            $inventories = $this->balanceLocks->lock($allProductPairs->concat($requiredPairs));
            $products = Product::query()
                ->whereIn('id', $productIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            if ($products->count() !== $productIds->count()) {
                throw new InventoryException('Un producto del pedido ya no existe.');
            }

            $movements = [];
            foreach ($itemsToReturn as $item) {
                $key = 'cancellation-order-item-'.$item->id;
                $existing = InventoryMovement::query()->where('idempotency_key', $key)->lockForUpdate()->first();
                if ($existing) {
                    $movements[] = $existing;

                    continue;
                }
                $inventory = $inventories->get($this->balanceLocks->key((int) $item->warehouse_id, (int) $item->product_id));
                if (! $inventory instanceof WarehouseInventory) {
                    throw new InventoryException('No se obtuvo el saldo bloqueado para reponer la cancelación.');
                }
                $before = (int) $inventory->quantity;
                $after = $before + (int) $item->quantity;
                $inventory->update(['quantity' => $after]);
                $movements[] = InventoryMovement::create([
                    'warehouse_id' => $inventory->warehouse_id,
                    'product_id' => $inventory->product_id,
                    'user_id' => $user?->id,
                    'type' => InventoryMovement::CANCELLATION_RETURN,
                    'quantity' => (int) $item->quantity,
                    'quantity_before' => $before,
                    'quantity_after' => $after,
                    'reason' => 'Reposición por cancelación de pedido',
                    'reference_type' => 'order',
                    'reference_id' => (string) $order->id,
                    'idempotency_key' => $key,
                    'metadata' => [],
                    'created_at' => now(),
                ]);
            }

            foreach ($products as $product) {
                $total = (int) $inventories->where('product_id', $product->id)->sum('quantity');
                Product::whereKey($product->id)->update(['stock' => $total]);
            }

            return $movements;
        });
    }

    /**
     * @return array{reservations: array<int, InventoryReservation>, items: Collection, historical_reservations: Collection, fulfillment_histories: Collection}
     */
    private function reserveVersionedForResolutionCaseWithinSavepoint(Order $order, PaymentResolutionCase $case, \DateTimeInterface $expiresAt): array
    {
        if ($expiresAt <= now()) {
            $this->reservationConflict('El vencimiento de la reserva versionada debe estar en el futuro.');
        }

        $context = $this->lockResolutionReservationContext($order, $case);
        $existing = $context['historicalReservations']->where('resolution_case_id', $context['case']->id)->values();
        if ($existing->isNotEmpty()) {
            $coherent = $this->coherentExistingCaseReservations($context);
            if ($coherent !== null) {
                return $this->reservationResultWithLockedEvidence($coherent, $context);
            }

            $this->reservationConflict('Las reservas existentes del caso son inconsistentes.');
        }

        $plans = [];
        foreach ($context['items'] as $item) {
            $history = $context['historicalReservations']->where('order_item_id', $item->id);
            $sequence = ((int) ($history->max('reservation_sequence') ?? 0)) + 1;
            $plans[] = ['item' => $item, 'sequence' => $sequence];
        }

        $this->assertVersionedReservationCapacity($plans, $context['inventories']);

        $created = [];
        foreach ($plans as $plan) {
            /** @var OrderItem $item */
            $item = $plan['item'];
            $sequence = $plan['sequence'];
            $inventory = $this->lockedInventoryFor($context['inventories'], $item->warehouse_id, $item->product_id);
            $reservation = InventoryReservation::forceCreate([
                'order_id' => $context['order']->id,
                'order_item_id' => $item->id,
                'reservation_sequence' => $sequence,
                'resolution_case_id' => $context['case']->id,
                'product_id' => $item->product_id,
                'warehouse_id' => $item->warehouse_id,
                'quantity' => $item->quantity,
                'status' => InventoryReservation::ACTIVE,
                'expires_at' => $expiresAt,
                'idempotency_key' => $this->resolutionReservationKey($context['case']->id, $item->id, $sequence),
                'metadata' => [],
            ]);
            $inventory->update(['reserved_quantity' => (int) $inventory->reserved_quantity + (int) $item->quantity]);
            $created[] = $reservation;
        }

        return $this->reservationResultWithLockedEvidence($created, $context);
    }

    /**
     * @param  array<int, InventoryReservation>  $reservations
     * @param  array{items: Collection, historicalReservations: Collection, fulfillmentHistories: Collection}  $context
     * @return array{reservations: array<int, InventoryReservation>, items: Collection, historical_reservations: Collection, fulfillment_histories: Collection}
     */
    private function reservationResultWithLockedEvidence(array $reservations, array $context): array
    {
        return [
            'reservations' => $reservations,
            'items' => $context['items'],
            'historical_reservations' => $context['historicalReservations'],
            'fulfillment_histories' => $context['fulfillmentHistories'],
        ];
    }

    /**
     * @return array{processed: int, skipped: int, reservations: array<int, InventoryReservation>}
     */
    private function consumeVersionedReservationsForResolutionCaseWithinSavepoint(Order $order, PaymentResolutionCase $case, ?User $user): array
    {
        $context = $this->lockResolutionReservationContext($order, $case);
        $reservations = $context['historicalReservations']
            ->where('resolution_case_id', $context['case']->id)
            ->sortBy('id')
            ->values();
        $this->assertCompleteCaseReservationSet($context['items'], $reservations, $context['case']->id);

        if ($reservations->every(fn (InventoryReservation $reservation) => $reservation->status === InventoryReservation::CONSUMED)) {
            $this->assertCoherentConsumedCaseReservations($context['order'], $reservations, $context['inventories']);

            return ['processed' => 0, 'skipped' => $reservations->count(), 'reservations' => $reservations->all()];
        }

        if ($reservations->contains(fn (InventoryReservation $reservation) => $reservation->status !== InventoryReservation::ACTIVE)) {
            $this->reservationConflict('Las reservas del caso deben estar activas para consumirse.');
        }

        $this->assertVersionedConsumptionCapacity($reservations, $context['inventories']);
        foreach ($reservations as $reservation) {
            $existingMovement = InventoryMovement::where('idempotency_key', $this->saleKey($reservation->order_item_id))
                ->lockForUpdate()
                ->first();
            if ($existingMovement !== null) {
                $this->reservationConflict('Existe un movimiento de venta previo incompatible con la reserva del caso.');
            }
        }

        $processed = 0;
        foreach ($reservations as $reservation) {
            $inventory = $this->lockedInventoryFor($context['inventories'], $reservation->warehouse_id, $reservation->product_id);
            $before = (int) $inventory->quantity;
            $after = $before - (int) $reservation->quantity;
            $inventory->update([
                'quantity' => $after,
                'reserved_quantity' => (int) $inventory->reserved_quantity - (int) $reservation->quantity,
            ]);
            $reservation->update(['status' => InventoryReservation::CONSUMED, 'consumed_at' => now()]);
            InventoryMovement::create([
                'warehouse_id' => $reservation->warehouse_id,
                'product_id' => $reservation->product_id,
                'user_id' => $user?->id,
                'type' => InventoryMovement::SALE,
                'quantity' => $reservation->quantity,
                'quantity_before' => $before,
                'quantity_after' => $after,
                'reason' => 'Venta por pedido',
                'reference_type' => 'order',
                'reference_id' => (string) $context['order']->id,
                'idempotency_key' => $this->saleKey($reservation->order_item_id),
                'metadata' => [],
                'created_at' => now(),
            ]);
            $processed++;
        }

        foreach ($context['products'] as $product) {
            $this->syncProductStockFromLockedBalances($product, $context['lockedProductInventories']);
        }

        return ['processed' => $processed, 'skipped' => 0, 'reservations' => $reservations->all()];
    }

    /**
     * The caller owns the financial lock hierarchy. This primitive begins at
     * OrderItems and never reacquires PaymentSubmission, Order or Case locks.
     * Its own canonical order is items by id, historical reservations by id,
     * warehouses by id, balances by (warehouse_id, product_id), products by id,
     * then fulfillment histories by id. Expiration and cancellation also write
     * fulfillment history only after reservation/inventory work, so history is
     * deliberately last to avoid a lock-order inversion.
     *
     * @return array{order: Order, case: PaymentResolutionCase, items: \Illuminate\Support\Collection, historicalReservations: \Illuminate\Support\Collection, inventories: array<int, array<int, WarehouseInventory>>, products: \Illuminate\Support\Collection, fulfillmentHistories: \Illuminate\Support\Collection}
     */
    private function lockResolutionReservationContext(
        Order $order,
        PaymentResolutionCase $case,
        array $allowedStatuses = [PaymentResolutionCase::OPEN],
        array $allowedTypes = [null, PaymentResolutionCase::STOCK_REASSIGNMENT]
    ): array {
        if (! $order->exists || ! $case->exists) {
            $this->reservationConflict('El pedido o caso de resolución no existe.');
        }
        $submission = $case->relationLoaded('submission')
            ? $case->submission
            : PaymentSubmission::find($case->payment_submission_id);
        if (! $submission || (int) $submission->order_id !== (int) $order->id) {
            $this->reservationConflict('El caso de resolución no corresponde al pedido bloqueado.');
        }
        if (! in_array($case->status, $allowedStatuses, true)
            || ! in_array($case->type, $allowedTypes, true)) {
            $this->reservationConflict('El caso de resolución no permite reasignar reservas.');
        }

        $items = OrderItem::where('order_id', $order->id)->orderBy('id')->lockForUpdate()->get();
        if ($items->isEmpty()) {
            $this->reservationConflict('El pedido no tiene ítems para reasignar.');
        }
        if ($items->contains(fn (OrderItem $item) => ! $item->warehouse_id || ! $item->product_id || (int) $item->quantity <= 0)) {
            $this->reservationConflict('Los ítems del pedido no tienen una estructura de almacén válida.');
        }

        $historicalReservations = InventoryReservation::where(function ($query) use ($order, $items) {
            $query->where('order_id', $order->id)
                ->orWhereIn('order_item_id', $items->pluck('id'));
        })->orderBy('id')->lockForUpdate()->get();
        $itemsById = $items->keyBy('id');
        foreach ($historicalReservations as $reservation) {
            $item = $itemsById->get($reservation->order_item_id);
            if (! $item
                || (int) $reservation->order_id !== (int) $order->id
                || (int) $reservation->product_id !== (int) $item->product_id
                || (int) $reservation->warehouse_id !== (int) $item->warehouse_id
                || (int) $reservation->quantity !== (int) $item->quantity
                || (int) $reservation->reservation_sequence < 1) {
                $this->reservationConflict('La reserva histórica no coincide con el ítem original.');
            }
        }
        $warehouseIds = $items->pluck('warehouse_id')->map(fn ($id) => (int) $id)->unique()->sort()->values();
        $warehouses = Warehouse::with('branch')->whereIn('id', $warehouseIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        foreach ($warehouseIds as $warehouseId) {
            $warehouse = $warehouses->get($warehouseId);
            if (! $warehouse || ! $warehouse->is_active || ! $warehouse->branch?->is_active) {
                $this->reservationConflict('El almacén original y su sede deben estar activos.');
            }
        }

        $productIds = $items->pluck('product_id')->map(fn ($id) => (int) $id)->unique()->sort()->values();
        $allProductInventoryPairs = WarehouseInventory::query()
            ->whereIn('product_id', $productIds)
            ->get(['warehouse_id', 'product_id'])
            ->map(fn (WarehouseInventory $inventory) => [
                'warehouse_id' => (int) $inventory->warehouse_id,
                'product_id' => (int) $inventory->product_id,
            ]);
        $requiredPairs = $items->map(fn (OrderItem $item) => [
            'warehouse_id' => (int) $item->warehouse_id,
            'product_id' => (int) $item->product_id,
        ]);
        try {
            $lockedProductInventories = $this->balanceLocks->lock($allProductInventoryPairs->concat($requiredPairs));
        } catch (InventoryException $exception) {
            $this->reservationConflict($exception->getMessage());
        }
        $inventories = [];
        foreach ($this->balanceLocks->canonicalPairs($requiredPairs) as $pair) {
            $inventory = $lockedProductInventories->get($this->balanceLocks->key($pair['warehouse_id'], $pair['product_id']));
            if (! $inventory) {
                $this->reservationConflict('No existe inventario físico para el almacén original del ítem.');
            }
            $inventories[(int) $pair['warehouse_id']][(int) $pair['product_id']] = $inventory;
        }

        $products = Product::whereIn('id', $productIds)->orderBy('id')->lockForUpdate()->get();
        if ($products->count() !== $productIds->count()) {
            $this->reservationConflict('Un producto de la reserva ya no existe.');
        }

        foreach ($products as $product) {
            $physicalStock = (int) $lockedProductInventories
                ->where('product_id', $product->id)
                ->sum('quantity');
            if ((int) $product->stock !== $physicalStock) {
                $this->reservationConflict('El stock del producto no coincide con sus saldos físicos.');
            }
        }

        $fulfillmentHistories = OrderFulfillmentHistory::query()
            ->where('order_id', $order->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        return compact('items', 'historicalReservations', 'inventories', 'products', 'fulfillmentHistories', 'lockedProductInventories') + [
            'order' => $order,
            'case' => $case,
        ];
    }

    /** @param array<int, array{item: OrderItem, sequence: int}> $plans */
    private function assertVersionedReservationCapacity(array $plans, array $inventories): void
    {
        $demands = [];
        foreach ($plans as $plan) {
            $item = $plan['item'];
            $matched = false;
            foreach ($demands as &$demand) {
                if ($demand['warehouse_id'] === (int) $item->warehouse_id && $demand['product_id'] === (int) $item->product_id) {
                    $demand['quantity'] += (int) $item->quantity;
                    $matched = true;
                    break;
                }
            }
            unset($demand);
            if (! $matched) {
                $demands[] = ['warehouse_id' => (int) $item->warehouse_id, 'product_id' => (int) $item->product_id, 'quantity' => (int) $item->quantity];
            }
        }
        foreach ($demands as $demand) {
            $inventory = $this->lockedInventoryFor($inventories, $demand['warehouse_id'], $demand['product_id']);
            $this->assertNonNegativeBalance($inventory);
            if ((int) $inventory->quantity - (int) $inventory->reserved_quantity < $demand['quantity']) {
                throw new InsufficientVersionedInventoryStockException('No hay stock disponible suficiente para reasignar todas las reservas.');
            }
        }
    }

    private function assertVersionedConsumptionCapacity(\Illuminate\Support\Collection $reservations, array $inventories): void
    {
        $demands = [];
        foreach ($reservations as $reservation) {
            $matched = false;
            foreach ($demands as &$demand) {
                if ($demand['warehouse_id'] === (int) $reservation->warehouse_id && $demand['product_id'] === (int) $reservation->product_id) {
                    $demand['quantity'] += (int) $reservation->quantity;
                    $matched = true;
                    break;
                }
            }
            unset($demand);
            if (! $matched) {
                $demands[] = ['warehouse_id' => (int) $reservation->warehouse_id, 'product_id' => (int) $reservation->product_id, 'quantity' => (int) $reservation->quantity];
            }
        }
        foreach ($demands as $demand) {
            $inventory = $this->lockedInventoryFor($inventories, $demand['warehouse_id'], $demand['product_id']);
            $this->assertNonNegativeBalance($inventory);
            if ((int) $inventory->quantity < $demand['quantity'] || (int) $inventory->reserved_quantity < $demand['quantity']) {
                $this->reservationConflict('El inventario reservado es inconsistente para consumir el caso.');
            }
        }
    }

    private function assertCompleteCaseReservationSet(\Illuminate\Support\Collection $items, \Illuminate\Support\Collection $reservations, int $caseId): void
    {
        if ($reservations->count() !== $items->count()) {
            $this->reservationConflict('El caso no tiene exactamente una reserva por ítem.');
        }
        foreach ($items as $item) {
            $matches = $reservations->where('order_item_id', $item->id);
            if ($matches->count() !== 1) {
                $this->reservationConflict('El caso tiene reservas duplicadas o faltantes por ítem.');
            }
            $reservation = $matches->first();
            if ((int) $reservation->order_id !== (int) $item->order_id
                || (int) $reservation->resolution_case_id !== $caseId
                || (int) $reservation->product_id !== (int) $item->product_id
                || (int) $reservation->warehouse_id !== (int) $item->warehouse_id
                || (int) $reservation->quantity !== (int) $item->quantity
                || ! $reservation->expires_at) {
                $this->reservationConflict('La reserva del caso no coincide con el ítem original.');
            }
        }
    }

    /** @return array<int, InventoryReservation>|null */
    private function coherentResolutionCaseReservations(int $orderId, int $caseId): ?array
    {
        $items = OrderItem::where('order_id', $orderId)->orderBy('id')->get();
        $all = InventoryReservation::where('order_id', $orderId)->orderBy('id')->get();
        $reservations = $all->where('resolution_case_id', $caseId)->values();
        if ($items->isEmpty() || $reservations->count() !== $items->count()) {
            return null;
        }
        foreach ($items as $item) {
            $matches = $reservations->where('order_item_id', $item->id);
            if ($matches->count() !== 1) {
                return null;
            }
            $reservation = $matches->first();
            $maxSequence = (int) ($all->where('order_item_id', $item->id)->max('reservation_sequence') ?? 0);
            if ((int) $reservation->order_id !== $orderId
                || (int) $reservation->product_id !== (int) $item->product_id
                || (int) $reservation->warehouse_id !== (int) $item->warehouse_id
                || (int) $reservation->quantity !== (int) $item->quantity
                || (int) $reservation->reservation_sequence !== $maxSequence
                || $reservation->idempotency_key !== $this->resolutionReservationKey($caseId, $item->id, $maxSequence)
                || ! in_array($reservation->status, [InventoryReservation::ACTIVE, InventoryReservation::CONSUMED], true)
                || ! $reservation->expires_at) {
                return null;
            }
        }

        return $reservations->sortBy('order_item_id')->values()->all();
    }

    /**
     * @param  array{order: Order, case: PaymentResolutionCase, items: \Illuminate\Support\Collection, historicalReservations: \Illuminate\Support\Collection, inventories: array<int, array<int, WarehouseInventory>>, products: \Illuminate\Support\Collection}  $context
     * @return array<int, InventoryReservation>|null
     */
    private function coherentExistingCaseReservations(array $context): ?array
    {
        $coherent = $this->coherentResolutionCaseReservations($context['order']->id, $context['case']->id);
        if ($coherent === null) {
            return null;
        }

        $reservations = collect($coherent);
        if ($reservations->every(fn (InventoryReservation $reservation) => $reservation->status === InventoryReservation::ACTIVE)) {
            return $coherent;
        }
        if (! $reservations->every(fn (InventoryReservation $reservation) => $reservation->status === InventoryReservation::CONSUMED)) {
            return null;
        }

        $this->assertCoherentConsumedCaseReservations($context['order'], $reservations, $context['inventories']);

        return $coherent;
    }

    private function assertCoherentConsumedCaseReservations(Order $order, \Illuminate\Support\Collection $reservations, array $inventories): void
    {
        $expectedMovementKeys = $reservations
            ->map(fn (InventoryReservation $reservation) => $this->saleKey($reservation->order_item_id))
            ->sort()
            ->values();
        $orderSaleMovements = InventoryMovement::query()
            ->where('reference_type', 'order')
            ->where('reference_id', (string) $order->id)
            ->where('type', InventoryMovement::SALE)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        if ($orderSaleMovements->count() !== $reservations->count()
            || $orderSaleMovements->pluck('idempotency_key')->sort()->values()->all() !== $expectedMovementKeys->all()) {
            $this->reservationConflict('Los movimientos de venta del pedido no coinciden exactamente con sus reservas consumidas.');
        }

        foreach ($reservations as $reservation) {
            if (! $reservation->consumed_at || $reservation->released_at) {
                $this->reservationConflict('Una reserva consumida no conserva una evidencia temporal coherente.');
            }
            $movements = InventoryMovement::where('idempotency_key', $this->saleKey($reservation->order_item_id))
                ->lockForUpdate()
                ->get();
            if ($movements->count() !== 1) {
                $this->reservationConflict('La reserva consumida no tiene exactamente un movimiento de venta.');
            }
            $movement = $movements->first();
            if ($movement->type !== InventoryMovement::SALE
                || (int) $movement->warehouse_id !== (int) $reservation->warehouse_id
                || (int) $movement->product_id !== (int) $reservation->product_id
                || (int) $movement->quantity !== (int) $reservation->quantity
                || (int) $movement->quantity_before - (int) $movement->quantity_after !== (int) $reservation->quantity
                || $movement->reference_type !== 'order'
                || (string) $movement->reference_id !== (string) $order->id) {
                $this->reservationConflict('El movimiento histórico de venta no es coherente con el caso.');
            }
            $this->assertNonNegativeBalance($this->lockedInventoryFor($inventories, $reservation->warehouse_id, $reservation->product_id));
        }
    }

    private function lockedInventoryFor(array $inventories, int $warehouseId, int $productId): WarehouseInventory
    {
        $inventory = $inventories[$warehouseId][$productId] ?? null;
        if (! $inventory instanceof WarehouseInventory) {
            $this->reservationConflict('No se obtuvo el saldo bloqueado de la reserva.');
        }

        return $inventory;
    }

    private function assertNonNegativeBalance(WarehouseInventory $inventory): void
    {
        if ((int) $inventory->quantity < 0 || (int) $inventory->reserved_quantity < 0 || (int) $inventory->reserved_quantity > (int) $inventory->quantity) {
            $this->reservationConflict('El saldo físico y reservado del inventario es incompatible.');
        }
    }

    private function syncProductStockFromLockedBalances(Product $product, Collection $inventories): void
    {
        $total = (int) $inventories->where('product_id', $product->id)->sum('quantity');
        // Product::update() honors $fillable and stock intentionally is not a
        // public mass-assignable attribute. This row is already locked here.
        Product::whereKey($product->id)->update(['stock' => $total]);
        $product->setAttribute('stock', $total);
    }

    private function resolutionReservationKey(int $caseId, int $itemId, int $sequence): string
    {
        return 'treasury-resolution-reassign-case-'.$caseId.'-item-'.$itemId.'-seq-'.$sequence;
    }

    private function saleKey(int $orderItemId): string
    {
        return 'sale-order-item-'.$orderItemId;
    }

    private function requireOuterTransaction(): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Las reservas versionadas requieren una transacción exterior abierta.');
        }
    }

    private function reservationConflict(string $message): never
    {
        throw new InventoryReservationConflictException($message);
    }

    private function change(Product $product, Warehouse $warehouse, int $delta, string $type, string $reason, ?User $user, array $metadata = [], ?string $referenceType = null, ?string $referenceId = null, ?string $referenceKey = null): InventoryMovement
    {
        $this->positive(abs($delta));

        return DB::transaction(function () use ($product, $warehouse, $delta, $type, $reason, $user, $metadata, $referenceType, $referenceId, $referenceKey) {
            // La fila de saldo serializa operaciones del mismo producto/almacén. La consulta
            // idempotente ocurre después del lock y el UNIQUE sigue siendo la garantía final.
            $context = $this->lockProductInventoryContext($product, [$warehouse->id], [$warehouse->id]);
            $inventory = $this->lockedBalance($context['inventories'], $warehouse->id, $product->id);
            if ($referenceKey && ($existing = InventoryMovement::where('idempotency_key', $referenceKey)->first())) {
                return $existing;
            }

            $movement = $this->applyLocked($inventory, $delta, $type, $reason, $user, $referenceType, $referenceId, $referenceKey, $metadata);
            $this->syncProductStockFromLockedBalances($context['product'], $context['inventories']);

            return $movement;
        });
    }

    /**
     * Serializes balance creation through the complete ordered warehouse set,
     * then locks every balance for the product before locking Product.
     *
     * @param  iterable<int, int|string>  $requiredWarehouseIds
     * @return array{product: Product, inventories: Collection<string, WarehouseInventory>}
     */
    private function lockProductInventoryContext(Product $product, iterable $requiredWarehouseIds, iterable $creatableWarehouseIds = []): array
    {
        $requiredIds = collect($requiredWarehouseIds)->map(fn ($id) => (int) $id)->unique()->sort()->values();
        $creatableIds = collect($creatableWarehouseIds)->map(fn ($id) => (int) $id)->unique()->sort()->values();
        if ($creatableIds->diff($requiredIds)->isNotEmpty()) {
            throw new InventoryException('Un saldo creable no pertenece al conjunto de almacenes requerido.');
        }
        $warehouses = Warehouse::query()->with('branch')->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        foreach ($requiredIds as $warehouseId) {
            $warehouse = $warehouses->get($warehouseId);
            if (! $warehouse instanceof Warehouse) {
                throw new InventoryException('Un almacén requerido ya no existe.');
            }
            $this->assertActive($warehouse);

            if ($creatableIds->contains($warehouseId)) {
                WarehouseInventory::insertOrIgnore([
                    'warehouse_id' => $warehouse->id,
                    'product_id' => $product->id,
                    'quantity' => 0,
                    'reserved_quantity' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        $pairs = WarehouseInventory::query()
            ->where('product_id', $product->id)
            ->get(['warehouse_id', 'product_id'])
            ->map(fn (WarehouseInventory $inventory) => [
                'warehouse_id' => (int) $inventory->warehouse_id,
                'product_id' => (int) $inventory->product_id,
            ]);
        $requiredPairs = $requiredIds->map(fn (int $warehouseId) => [
            'warehouse_id' => $warehouseId,
            'product_id' => (int) $product->id,
        ]);
        $inventories = $this->balanceLocks->lock($pairs->concat($requiredPairs));
        $lockedProduct = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
        if ((int) $lockedProduct->stock < 0) {
            throw new InventoryException('El stock agregado del producto es incompatible.');
        }

        return ['product' => $lockedProduct, 'inventories' => $inventories];
    }

    private function lockedBalance(Collection $inventories, int $warehouseId, int $productId): WarehouseInventory
    {
        $inventory = $inventories->get($this->balanceLocks->key($warehouseId, $productId));
        if (! $inventory instanceof WarehouseInventory) {
            throw new InventoryException('No se obtuvo el saldo bloqueado requerido.');
        }

        return $inventory;
    }

    private function applyLocked(WarehouseInventory $inventory, int $delta, string $type, string $reason, ?User $user, ?string $referenceType, ?string $referenceId, ?string $referenceKey, array $metadata): InventoryMovement
    {
        $before = (int) $inventory->quantity;
        $after = $before + $delta;
        if ($after < 0) {
            throw new InventoryException('La operación supera el stock disponible.');
        }
        if ($after < (int) $inventory->reserved_quantity) {
            throw new InventoryException('La operacion supera el stock disponible no reservado.');
        }
        $inventory->update(['quantity' => $after]);
        $movement = InventoryMovement::create(['warehouse_id' => $inventory->warehouse_id, 'product_id' => $inventory->product_id, 'user_id' => $user?->id, 'type' => $type, 'quantity' => abs($delta), 'quantity_before' => $before, 'quantity_after' => $after, 'reason' => trim($reason), 'reference_type' => $referenceType, 'reference_id' => $referenceId, 'idempotency_key' => $referenceKey, 'metadata' => array_filter($metadata, fn ($v) => $v !== null && $v !== ''), 'created_at' => now()]);

        return $movement;
    }

    private function assertActive(Warehouse $warehouse): void
    {
        $warehouse->loadMissing('branch');
        if (! $warehouse->is_active || ! $warehouse->branch?->is_active) {
            throw new InventoryException('El almacén y su sede deben estar activos.');
        }
    }

    private function positive(int $quantity): void
    {
        if ($quantity <= 0) {
            throw new InventoryException('La cantidad debe ser un entero positivo.');
        }
    }
}
