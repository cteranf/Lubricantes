<?php

namespace App\Services;

use App\Exceptions\InventoryException;
use App\Exceptions\InventoryReceiptConflictException;
use App\Models\InventoryReceipt;
use App\Models\InventoryReceiptLine;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InventoryReceiptService
{
    public function __construct(private InventoryService $inventory) {}

    public function createDraft(array $data, User $actor): InventoryReceipt
    {
        $this->assertLines($data['lines']);

        $receipt = DB::transaction(function () use ($data, $actor) {
            $warehouse = $this->lockActiveWarehouse((int) $data['warehouse_id']);
            $receipt = new InventoryReceipt;
            $receipt->forceFill([
                'reference' => 'REC-'.Str::upper(Str::random(12)),
                'external_reference' => $data['external_reference'] ?? null,
                'warehouse_id' => $warehouse->id,
                'status' => InventoryReceipt::DRAFT,
                'notes' => $data['notes'] ?? null,
                'version' => 1,
                'created_by' => $actor->id,
            ])->save();
            $this->replaceLines($receipt, $data['lines']);

            return $receipt;
        }, 3);

        return $this->loadReceipt($receipt->id);
    }

    public function updateDraft(int $receiptId, array $data, User $actor): InventoryReceipt
    {
        $this->assertLines($data['lines']);

        DB::transaction(function () use ($receiptId, $data, $actor) {
            $receipt = InventoryReceipt::query()->whereKey($receiptId)->lockForUpdate()->firstOrFail();
            $this->assertDraftVersion($receipt, (int) $data['version']);
            $warehouse = $this->lockActiveWarehouse((int) $data['warehouse_id']);
            $receipt->forceFill([
                'warehouse_id' => $warehouse->id,
                'external_reference' => $data['external_reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'version' => $receipt->version + 1,
            ])->save();
            $receipt->lines()->delete();
            $this->replaceLines($receipt, $data['lines']);
        }, 3);

        return $this->loadReceipt($receiptId);
    }

    public function confirm(int $receiptId, int $expectedVersion, User $actor): InventoryReceipt
    {
        DB::transaction(function () use ($receiptId, $expectedVersion, $actor) {
            $receipt = InventoryReceipt::query()->whereKey($receiptId)->lockForUpdate()->firstOrFail();

            // Confirmation is immutable and idempotent, even if a retry carries
            // an old optimistic version or comes from a different administrator.
            if ($receipt->status === InventoryReceipt::CONFIRMED) {
                return;
            }

            $this->assertDraftVersion($receipt, $expectedVersion);
            $lines = $receipt->lines()->orderBy('id')->lockForUpdate()->get();
            $this->assertLines($lines->map(fn (InventoryReceiptLine $line) => [
                'product_id' => $line->product_id,
                'quantity' => $line->quantity,
            ])->all());

            try {
                $this->inventory->receiveInventory((int) $receipt->warehouse_id, $lines, $actor, $receipt->reference);
            } catch (InventoryException) {
                throw InventoryReceiptConflictException::invalid('La recepción no se puede confirmar con el inventario actual.');
            }
            $receipt->forceFill([
                'status' => InventoryReceipt::CONFIRMED,
                'confirmed_by' => $actor->id,
                'confirmed_at' => now(),
                'version' => $receipt->version + 1,
            ])->save();
        }, 3);

        return $this->loadReceipt($receiptId);
    }

    public function cancel(int $receiptId, int $expectedVersion): InventoryReceipt
    {
        DB::transaction(function () use ($receiptId, $expectedVersion) {
            $receipt = InventoryReceipt::query()->whereKey($receiptId)->lockForUpdate()->firstOrFail();
            $this->assertDraftVersion($receipt, $expectedVersion);
            $receipt->forceFill([
                'status' => InventoryReceipt::CANCELLED,
                'version' => $receipt->version + 1,
            ])->save();
        }, 3);

        return $this->loadReceipt($receiptId);
    }

    public function loadReceipt(int $receiptId): InventoryReceipt
    {
        return InventoryReceipt::query()->with(['warehouse:id,code,name', 'creator:id,name', 'confirmer:id,name', 'lines.product:id,name,sku'])
            ->findOrFail($receiptId);
    }

    private function replaceLines(InventoryReceipt $receipt, array $lines): void
    {
        foreach (array_values($lines) as $sortOrder => $line) {
            $lineModel = new InventoryReceiptLine;
            $lineModel->forceFill([
                'product_id' => (int) $line['product_id'],
                'quantity' => (int) $line['quantity'],
                'sort_order' => $sortOrder,
            ]);
            $receipt->lines()->save($lineModel);
        }
    }

    private function assertLines(array $lines): void
    {
        $uniqueProducts = [];
        $totalUnits = 0;
        $maxLines = (int) config('inventory_receipts.max_lines', 100);
        $maxQuantity = (int) config('inventory_receipts.max_line_quantity', 2147483647);

        if (count($lines) < 1 || count($lines) > $maxLines) {
            throw InventoryReceiptConflictException::invalid('La recepción debe contener entre 1 y 100 productos.');
        }

        foreach ($lines as $line) {
            $productId = filter_var($line['product_id'] ?? null, FILTER_VALIDATE_INT);
            $quantity = filter_var($line['quantity'] ?? null, FILTER_VALIDATE_INT);
            if ($productId === false || $productId < 1 || $quantity === false || $quantity < 1 || $quantity > $maxQuantity) {
                throw InventoryReceiptConflictException::invalid('Las cantidades de la recepción no son válidas.');
            }
            if (isset($uniqueProducts[$productId])) {
                throw InventoryReceiptConflictException::invalid('No se permiten productos duplicados en una recepción.');
            }
            $uniqueProducts[$productId] = true;
            $totalUnits += $quantity;
        }

        if ($totalUnits > (int) config('inventory_receipts.max_total_units', 100000)) {
            throw InventoryReceiptConflictException::invalid('La recepción supera el máximo de unidades permitido.');
        }
    }

    private function lockActiveWarehouse(int $warehouseId): Warehouse
    {
        $warehouse = Warehouse::query()->whereKey($warehouseId)->lockForUpdate()->first();
        if (! $warehouse) {
            throw InventoryReceiptConflictException::invalid('El almacén seleccionado no está disponible.');
        }
        $warehouse->load('branch:id,is_active');
        if (! $warehouse->is_active || ! $warehouse->branch?->is_active) {
            throw InventoryReceiptConflictException::invalid('El almacén seleccionado no está disponible.');
        }

        return $warehouse;
    }

    private function assertDraftVersion(InventoryReceipt $receipt, int $expectedVersion): void
    {
        if ($receipt->status !== InventoryReceipt::DRAFT) {
            throw new InventoryReceiptConflictException('La recepción ya no se puede modificar.');
        }
        if ((int) $receipt->version !== $expectedVersion) {
            throw new InventoryReceiptConflictException('La recepción cambió. Actualice la información antes de continuar.');
        }
    }
}
