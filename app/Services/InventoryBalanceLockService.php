<?php

namespace App\Services;

use App\Exceptions\InventoryException;
use App\Models\WarehouseInventory;
use Illuminate\Support\Collection;
use LogicException;

/**
 * Internal lock coordinator for physical inventory balances.
 *
 * Every inventory workflow must acquire balance rows in the same numeric order:
 * warehouse_id, product_id, then the physical row id as a defensive tie-breaker.
 */
final class InventoryBalanceLockService
{
    /**
     * @param  iterable<int, array{0?: int|string, 1?: int|string, warehouse_id?: int|string, product_id?: int|string}>  $pairs
     * @return Collection<string, WarehouseInventory>
     */
    public function lock(iterable $pairs): Collection
    {
        $connection = (new WarehouseInventory)->getConnection();
        if ($connection->transactionLevel() < 1) {
            throw new LogicException('El bloqueo de saldos requiere una transacción exterior abierta.');
        }

        $canonical = $this->canonicalPairs($pairs);
        if ($canonical === []) {
            return collect();
        }

        $locked = WarehouseInventory::query()
            ->where(function ($query) use ($canonical) {
                foreach ($canonical as $pair) {
                    $query->orWhere(function ($candidate) use ($pair) {
                        $candidate->where('warehouse_id', $pair['warehouse_id'])
                            ->where('product_id', $pair['product_id']);
                    });
                }
            })
            ->orderBy('warehouse_id')
            ->orderBy('product_id')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($locked->count() !== count($canonical)) {
            throw new InventoryException('Los saldos de inventario requeridos no son únicos o están incompletos.');
        }

        $byPair = collect();
        foreach ($locked as $inventory) {
            $key = $this->key((int) $inventory->warehouse_id, (int) $inventory->product_id);
            if ($byPair->has($key)) {
                throw new InventoryException('Existe más de un saldo para el mismo almacén y producto.');
            }
            if ((int) $inventory->quantity < 0
                || (int) $inventory->reserved_quantity < 0
                || (int) $inventory->reserved_quantity > (int) $inventory->quantity) {
                throw new InventoryException('El saldo físico y reservado del inventario es incompatible.');
            }
            $byPair->put($key, $inventory);
        }

        foreach ($canonical as $pair) {
            if (! $byPair->has($this->key($pair['warehouse_id'], $pair['product_id']))) {
                throw new InventoryException('No existe un saldo de inventario requerido.');
            }
        }

        return $byPair;
    }

    /**
     * @param  iterable<int, array{0?: int|string, 1?: int|string, warehouse_id?: int|string, product_id?: int|string}>  $pairs
     * @return array<int, array{warehouse_id: int, product_id: int}>
     */
    public function canonicalPairs(iterable $pairs): array
    {
        $unique = [];
        foreach ($pairs as $pair) {
            $warehouseId = (int) ($pair['warehouse_id'] ?? $pair[0] ?? 0);
            $productId = (int) ($pair['product_id'] ?? $pair[1] ?? 0);
            if ($warehouseId < 1 || $productId < 1) {
                throw new InventoryException('El par de almacén y producto es inválido.');
            }
            $unique[$this->key($warehouseId, $productId)] = [
                'warehouse_id' => $warehouseId,
                'product_id' => $productId,
            ];
        }

        $canonical = array_values($unique);
        usort($canonical, fn (array $left, array $right): int => [
            $left['warehouse_id'],
            $left['product_id'],
        ] <=> [
            $right['warehouse_id'],
            $right['product_id'],
        ]);

        return $canonical;
    }

    public function key(int $warehouseId, int $productId): string
    {
        return $warehouseId.':'.$productId;
    }
}
