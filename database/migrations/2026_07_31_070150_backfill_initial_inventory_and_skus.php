<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::transaction(function () {
            $now = now();
            $branch = DB::table('branches')->where('code', 'PRINCIPAL')->first();
            if ($branch && ! $branch->is_active) {
                throw new RuntimeException('La sede PRINCIPAL existente está inactiva; corríjala antes de migrar.');
            }
            $branchId = $branch?->id ?? DB::table('branches')->insertGetId([
                'code' => 'PRINCIPAL', 'name' => 'Sede Principal', 'address' => 'Por configurar',
                'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
            ]);

            $warehouse = DB::table('warehouses')->where('code', 'ALM-PRINCIPAL')->first();
            if ($warehouse && ((int) $warehouse->branch_id !== (int) $branchId || ! $warehouse->is_active || ! $warehouse->is_default)) {
                throw new RuntimeException('ALM-PRINCIPAL existe con sede, estado o condición predeterminada incompatible.');
            }
            if (! $warehouse && DB::table('warehouses')->where('branch_id', $branchId)->where('is_active', true)->where('is_default', true)->exists()) {
                throw new RuntimeException('La sede PRINCIPAL ya tiene otro almacén predeterminado activo.');
            }
            $warehouseId = $warehouse?->id ?? DB::table('warehouses')->insertGetId([
                'branch_id' => $branchId, 'code' => 'ALM-PRINCIPAL', 'name' => 'Almacén Principal',
                'is_default' => true, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
            ]);

            DB::table('products')->orderBy('id')->chunkById(200, function ($products) use ($warehouseId, $now) {
                foreach ($products as $product) {
                    if (! $product->sku) {
                        $base = 'LUB-'.str_pad((string) $product->id, 6, '0', STR_PAD_LEFT);
                        $sku = $base;
                        $suffix = 1;
                        while (DB::table('products')->where('sku', $sku)->where('id', '!=', $product->id)->exists()) {
                            $sku = $base.'-'.$suffix++;
                        }
                        DB::table('products')->where('id', $product->id)->whereNull('sku')->update(['sku' => $sku]);
                    }

                    $inventory = DB::table('warehouse_inventories')
                        ->where('warehouse_id', $warehouseId)->where('product_id', $product->id)->first();
                    if ($inventory) continue; // Nunca sobrescribe un saldo operativo existente.

                    $quantity = max(0, (int) $product->stock);
                    $key = 'initial-product-'.$product->id;
                    if (DB::table('inventory_movements')->where('idempotency_key', $key)->exists()) {
                        throw new RuntimeException("Existe el movimiento {$key} sin su inventario inicial correspondiente.");
                    }
                    DB::table('warehouse_inventories')->insert([
                        'warehouse_id' => $warehouseId, 'product_id' => $product->id,
                        'quantity' => $quantity, 'created_at' => $now, 'updated_at' => $now,
                    ]);
                    if ($quantity > 0) DB::table('inventory_movements')->insert([
                        'warehouse_id' => $warehouseId, 'product_id' => $product->id, 'user_id' => null,
                        'type' => 'initial', 'quantity' => $quantity, 'quantity_before' => 0,
                        'quantity_after' => $quantity, 'reason' => 'Migración inicial desde products.stock',
                        'reference_type' => 'product', 'reference_id' => (string) $product->id,
                        'idempotency_key' => $key, 'metadata' => json_encode(['source' => 'products.stock']),
                        'created_at' => $now,
                    ]);
                }
            });
        });
    }

    public function down(): void
    {
        // Los rollbacks estructurales eliminan el inventario; los SKU se conservan por estabilidad.
    }
};
