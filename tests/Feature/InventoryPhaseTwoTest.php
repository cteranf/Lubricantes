<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\InventoryMovement;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseInventory;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use LogicException;
use Tests\TestCase;

class InventoryPhaseTwoTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_branch_and_customer_cannot(): void
    {
        Sanctum::actingAs($this->admin());
        $this->postJson('/api/v1/admin/branches', $this->branchPayload('NORTE'))->assertCreated()->assertJsonPath('code', 'NORTE');
        Sanctum::actingAs(User::factory()->create(['role' => 'customer']));
        $this->postJson('/api/v1/admin/branches', $this->branchPayload('SUR'))->assertForbidden();
    }

    public function test_duplicate_branch_code_is_rejected_and_active_dependencies_block_deactivation(): void
    {
        $admin = $this->admin();
        Sanctum::actingAs($admin);
        $branch = Branch::where('code', 'PRINCIPAL')->firstOrFail();
        $this->postJson('/api/v1/admin/branches', $this->branchPayload(' principal '))->assertUnprocessable();
        $this->patchJson("/api/v1/admin/branches/{$branch->id}/status", ['is_active' => false])->assertUnprocessable();
    }

    public function test_only_one_default_warehouse_is_kept_globally(): void
    {
        Sanctum::actingAs($this->admin());
        $branch = Branch::where('code', 'PRINCIPAL')->firstOrFail();
        $response = $this->postJson('/api/v1/admin/warehouses', ['branch_id' => $branch->id, 'code' => 'ALM-2', 'name' => 'Secundario', 'is_default' => true, 'is_active' => true])->assertCreated();
        $this->assertSame(1, Warehouse::where('is_default', true)->count());
        $this->assertTrue(Warehouse::find($response->json('id'))->is_default);
    }

    public function test_warehouse_with_stock_cannot_be_deactivated(): void
    {
        $admin = $this->admin();
        Sanctum::actingAs($admin);
        $warehouse = $this->warehouse();
        $product = $this->product();
        app(InventoryService::class)->manualIn($product, $warehouse, 3, 'Recepción', $admin);
        $this->patchJson("/api/v1/admin/warehouses/{$warehouse->id}/status", ['is_active' => false])->assertUnprocessable();
    }

    public function test_sku_is_generated_normalized_unique_and_stable(): void
    {
        $generated = $this->product(['sku' => null]);
        $this->assertSame('LUB-'.str_pad((string) $generated->id, 6, '0', STR_PAD_LEFT), $generated->sku);
        $manual = $this->product(['sku' => '  lub especial / 01  ']);
        $this->assertSame('LUB-ESPECIAL-01', $manual->sku);
        $sku = $manual->sku;
        $manual->update(['name' => 'Otro nombre']);
        $this->assertSame($sku, $manual->refresh()->sku);
        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->product(['sku' => $sku]);
    }

    public function test_manual_entries_outputs_and_adjustments_update_balances_and_audit(): void
    {
        $admin = $this->admin();
        $product = $this->product();
        $warehouse = $this->warehouse();
        $service = app(InventoryService::class);
        $service->manualIn($product, $warehouse, 10, 'Compra', $admin);
        $service->manualOut($product, $warehouse, 3, 'Merma', $admin);
        $movement = $service->adjust($product, $warehouse, 5, 'Conteo físico', $admin);
        $this->assertSame(5, WarehouseInventory::where('product_id', $product->id)->value('quantity'));
        $this->assertSame(5, $product->refresh()->stock);
        $this->assertSame(InventoryMovement::CORRECTION, $movement->type);
        $this->assertSame($admin->id, $movement->user_id);
        $this->assertSame('Conteo físico', $movement->reason);
    }

    public function test_zero_or_excessive_outputs_are_rejected_without_partial_change(): void
    {
        $product = $this->product();
        $warehouse = $this->warehouse();
        $service = app(InventoryService::class);
        $service->manualIn($product, $warehouse, 2, 'Ingreso');
        foreach ([0, 3] as $quantity) {
            try {
                $service->manualOut($product, $warehouse, $quantity, 'Salida');
                $this->fail('La salida debió ser rechazada.');
            } catch (\App\Exceptions\InventoryException) {
            }
        }
        $this->assertSame(2, WarehouseInventory::where('product_id', $product->id)->value('quantity'));
    }

    public function test_transfer_is_atomic_creates_both_movements_and_preserves_total(): void
    {
        $product = $this->product();
        $source = $this->warehouse();
        $destination = Warehouse::create(['branch_id' => $source->branch_id, 'code' => 'DEST', 'name' => 'Destino', 'is_default' => false, 'is_active' => true]);
        $service = app(InventoryService::class);
        $service->manualIn($product, $source, 8, 'Ingreso');
        [$out,$in] = $service->transfer($product, $source, $destination, 3, 'Reposición');
        $this->assertSame(5, WarehouseInventory::where(['product_id' => $product->id, 'warehouse_id' => $source->id])->value('quantity'));
        $this->assertSame(3, WarehouseInventory::where(['product_id' => $product->id, 'warehouse_id' => $destination->id])->value('quantity'));
        $this->assertSame($out->reference_id, $in->reference_id);
        $this->assertSame(8, $product->refresh()->stock);
    }

    public function test_transfer_to_same_warehouse_or_above_stock_changes_nothing(): void
    {
        $product = $this->product();
        $source = $this->warehouse();
        $destination = Warehouse::create(['branch_id' => $source->branch_id, 'code' => 'DEST-FAIL', 'name' => 'Destino', 'is_default' => false, 'is_active' => true]);
        $service = app(InventoryService::class);
        $service->manualIn($product, $source, 2, 'Ingreso');
        foreach ([fn () => $service->transfer($product, $source, $source, 1, 'Inválido'), fn () => $service->transfer($product, $source, $destination, 3, 'Exceso')] as $operation) {
            try {
                $operation();
                $this->fail('El traslado debió fallar.');
            } catch (\App\Exceptions\InventoryException) {
            }
        }
        $this->assertSame(2, $product->refresh()->stock);
        $this->assertFalse(WarehouseInventory::where(['product_id' => $product->id, 'warehouse_id' => $destination->id])->exists());
    }

    public function test_movements_cannot_be_edited_or_deleted(): void
    {
        $movement = app(InventoryService::class)->manualIn($this->product(), $this->warehouse(), 1, 'Ingreso');
        try {
            $movement->update(['reason' => 'Alterado']);
            $this->fail('No se debe editar.');
        } catch (LogicException) {
        }
        $this->expectException(LogicException::class);
        $movement->delete();
    }

    public function test_pending_order_uses_default_warehouse_and_cancellation_releases_reservation_once(): void
    {
        $admin = $this->admin();
        $customer = User::factory()->create(['role' => 'customer']);
        $product = $this->product(['stock' => 5]);
        Sanctum::actingAs($customer);
        $orderId = $this->postJson('/api/v1/orders', $this->orderPayload($product, 2))->assertCreated()->json('id');
        $this->assertDatabaseMissing('inventory_movements', ['type' => 'sale', 'reference_type' => 'order', 'reference_id' => (string) $orderId]);
        $this->assertSame(5, $product->refresh()->stock);
        $item = \App\Models\OrderItem::where('order_id', $orderId)->first();
        $this->assertSame($this->warehouse()->id, $item->warehouse_id);
        $this->assertDatabaseHas('inventory_reservations', ['order_item_id' => $item->id, 'status' => 'active', 'quantity' => 2]);
        Sanctum::actingAs($admin);
        $this->putJson("/api/v1/admin/orders/$orderId", ['status' => 'canceled'])->assertOk();
        $this->putJson("/api/v1/admin/orders/$orderId", ['status' => 'canceled'])->assertOk();
        $this->assertSame(5, $product->refresh()->stock);
        $this->assertSame(0, InventoryMovement::where('idempotency_key', 'cancellation-order-item-'.$item->id)->count());
        $this->assertDatabaseHas('inventory_reservations', ['order_item_id' => $item->id, 'status' => 'released']);
    }

    public function test_inventory_filters_history_pagination_and_authorization(): void
    {
        $admin = $this->admin();
        $product = $this->product(['sku' => 'FILTRO-01']);
        app(InventoryService::class)->manualIn($product, $this->warehouse(), 4, 'Ingreso', $admin);
        Sanctum::actingAs($admin);
        $this->getJson('/api/v1/admin/inventories?search=FILTRO-01')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/admin/inventory-movements')->assertOk()->assertJsonStructure(['data', 'current_page', 'last_page']);
        Sanctum::actingAs(User::factory()->create(['role' => 'customer']));
        $this->getJson('/api/v1/admin/inventories')->assertForbidden();
    }

    public function test_public_stock_uses_only_checkout_warehouse_while_admin_stock_is_global(): void
    {
        $product = $this->product();
        $principal = $this->warehouse();
        $secondary = Warehouse::create(['branch_id' => $principal->branch_id, 'code' => 'SECONDARY', 'name' => 'Secundario', 'is_default' => false, 'is_active' => true]);
        $service = app(InventoryService::class);
        $service->manualIn($product, $principal, 2, 'Ingreso vendible');
        $service->manualIn($product, $secondary, 10, 'Stock interno');

        $this->getJson('/api/v1/products/'.$product->slug)->assertOk()->assertJsonPath('stock', 2);
        Sanctum::actingAs($this->admin());
        $this->getJson('/api/v1/admin/products?search='.$product->sku)->assertOk()->assertJsonPath('data.0.stock', 12);
    }

    public function test_sale_and_cancellation_are_idempotent_per_order_item(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);
        $product = $this->product(['stock' => 5]);
        Sanctum::actingAs($customer);
        $orderId = $this->postJson('/api/v1/orders', $this->orderPayload($product, 2))->assertCreated()->json('id');
        $item = OrderItem::with(['product', 'warehouse'])->where('order_id', $orderId)->firstOrFail();
        $service = app(InventoryService::class);
        $service->sell($item, $customer);
        $this->assertSame(3, $product->refresh()->stock);
        $this->assertSame(1, InventoryMovement::where('idempotency_key', 'sale-order-item-'.$item->id)->count());
        $service->returnCancellation($item, $customer);
        $service->returnCancellation($item, $customer);
        $this->assertSame(5, $product->refresh()->stock);
        $this->assertSame(1, InventoryMovement::where('idempotency_key', 'cancellation-order-item-'.$item->id)->count());
    }

    public function test_database_unique_key_rejects_duplicate_business_movement(): void
    {
        $product = $this->product(['stock' => 1]);
        $movement = InventoryMovement::where('idempotency_key', 'initial-product-'.$product->id)->firstOrFail();
        $this->expectException(\Illuminate\Database\QueryException::class);
        InventoryMovement::create($movement->only(['warehouse_id', 'product_id', 'user_id', 'type', 'quantity', 'quantity_before', 'quantity_after', 'reason', 'reference_type', 'reference_id', 'idempotency_key', 'metadata', 'created_at']));
    }

    public function test_correction_uses_counted_balance_for_increase_reduction_and_no_change(): void
    {
        $product = $this->product(['stock' => 5]);
        $warehouse = $this->warehouse();
        $service = app(InventoryService::class);
        $increase = $service->adjust($product, $warehouse, 8, 'Conteo alto');
        $this->assertSame(8, $increase->quantity_after);
        $this->assertSame(8, $increase->metadata['counted_quantity']);
        $this->assertSame(3, $increase->metadata['difference']);
        $reduction = $service->adjust($product, $warehouse, 2, 'Conteo bajo');
        $this->assertSame(2, $reduction->quantity_after);
        $this->assertSame(-6, $reduction->metadata['difference']);
        $count = InventoryMovement::where('type', InventoryMovement::CORRECTION)->count();
        try {
            $service->adjust($product, $warehouse, 2, 'Sin diferencia');
            $this->fail('Un saldo sin cambios debe rechazarse.');
        } catch (\App\Exceptions\InventoryException) {
        }
        $this->assertSame($count, InventoryMovement::where('type', InventoryMovement::CORRECTION)->count());

        Sanctum::actingAs($this->admin());
        $this->postJson('/api/v1/admin/inventories/adjustments', ['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'action' => 'correction', 'quantity' => 4, 'quantity_before' => 999, 'quantity_after' => 999, 'reason' => 'Conteo API'])->assertCreated()->assertJsonPath('quantity_before', 2)->assertJsonPath('quantity_after', 4);
    }

    public function test_failed_transfer_rolls_back_both_balances_and_movements(): void
    {
        $product = $this->product(['stock' => 3]);
        $source = $this->warehouse();
        $destination = Warehouse::create(['branch_id' => $source->branch_id, 'code' => 'ROLLBACK-DEST', 'name' => 'Destino rollback', 'is_default' => false, 'is_active' => true]);
        $before = InventoryMovement::count();
        try {
            app(InventoryService::class)->transfer($product, $source, $destination, 4, 'Debe fallar');
            $this->fail('El traslado debía fallar.');
        } catch (\App\Exceptions\InventoryException) {
        }
        $this->assertSame(3, WarehouseInventory::where(['warehouse_id' => $source->id, 'product_id' => $product->id])->value('quantity'));
        $this->assertFalse(WarehouseInventory::where(['warehouse_id' => $destination->id, 'product_id' => $product->id])->exists());
        $this->assertSame($before, InventoryMovement::count());
    }

    public function test_failed_sale_rolls_back_order_items_inventory_and_sale_movements(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);
        $first = $this->product(['stock' => 2]);
        $second = $this->product(['stock' => 1]);
        Sanctum::actingAs($customer);
        $payload = $this->orderPayload($first, 1);
        $payload['items'][] = ['product_id' => $second->id, 'quantity' => 2];
        $this->postJson('/api/v1/orders', $payload)->assertUnprocessable();
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);
        $this->assertSame(2, $first->refresh()->stock);
        $this->assertSame(1, $second->refresh()->stock);
        $this->assertSame(0, InventoryMovement::where('type', InventoryMovement::SALE)->count());
    }

    public function test_inventory_filters_cover_branch_warehouse_name_and_sku(): void
    {
        $branch = Branch::create(['code' => 'FILTER-BR', 'name' => 'Sede filtro', 'address' => 'Av. Filtro', 'is_active' => true]);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'code' => 'FILTER-WH', 'name' => 'Almacén filtro', 'is_default' => false, 'is_active' => true]);
        $product = $this->product(['name' => 'Lubricante Buscable', 'sku' => 'FILTER-SKU']);
        app(InventoryService::class)->manualIn($product, $warehouse, 7, 'Ingreso filtro');
        Sanctum::actingAs($this->admin());
        foreach (['branch_id='.$branch->id, 'warehouse_id='.$warehouse->id, 'search=Lubricante%20Buscable', 'search=FILTER-SKU'] as $query) {
            $ids = collect($this->getJson('/api/v1/admin/inventories?'.$query)->assertOk()->json('data'))->pluck('product_id');
            $this->assertTrue($ids->contains($product->id));
        }
    }

    public function test_movements_have_no_update_or_delete_routes_and_stock_cannot_be_overwritten(): void
    {
        foreach (Route::getRoutes() as $route) {
            if (str_contains($route->uri(), 'inventory-movements')) {
                $this->assertEmpty(array_intersect($route->methods(), ['PUT', 'PATCH', 'DELETE']));
            }
        }
        $product = $this->product(['stock' => 4]);
        Sanctum::actingAs($this->admin());
        $this->putJson('/api/v1/admin/products/'.$product->id, ['stock' => 999])->assertOk();
        $this->assertSame(4, $product->refresh()->stock);
        $massAssigned = Product::create(['name' => 'Sin stock directo', 'slug' => 'sin-stock-directo', 'sku' => 'NO-STOCK-DIRECT', 'price' => 10, 'stock' => 999, 'is_active' => true]);
        $this->assertSame(0, $massAssigned->refresh()->stock);
    }

    public function test_generated_sku_avoids_collision_with_manual_future_pattern(): void
    {
        $manual = $this->product(['sku' => 'LUB-000002']);
        $this->assertSame(1, $manual->id);
        $generated = $this->product(['sku' => null]);
        $this->assertSame(2, $generated->id);
        $this->assertSame('LUB-000002-1', $generated->sku);
    }

    public function test_backfill_reentry_does_not_overwrite_existing_operational_inventory(): void
    {
        $product = $this->product(['stock' => 4]);
        $inventory = WarehouseInventory::where('product_id', $product->id)->firstOrFail();
        app(InventoryService::class)->manualIn($product, $this->warehouse(), 2, 'Operación posterior');
        $beforeMovements = InventoryMovement::count();
        $migration = require database_path('migrations/2026_07_31_070150_backfill_initial_inventory_and_skus.php');
        $migration->up();
        $this->assertSame(6, $inventory->refresh()->quantity);
        $this->assertSame($beforeMovements, InventoryMovement::count());
    }

    public function test_partially_failed_backfill_rolls_back_its_prior_data_changes(): void
    {
        $product = $this->product();
        $warehouse = $this->warehouse();
        WarehouseInventory::where(['warehouse_id' => $warehouse->id, 'product_id' => $product->id])->delete();
        \DB::table('products')->where('id', $product->id)->update(['sku' => null]);
        InventoryMovement::create(['warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'type' => InventoryMovement::INITIAL, 'quantity' => 1, 'quantity_before' => 0, 'quantity_after' => 1, 'reason' => 'Estado inconsistente simulado', 'reference_type' => 'product', 'reference_id' => (string) $product->id, 'idempotency_key' => 'initial-product-'.$product->id, 'created_at' => now()]);
        $migration = require database_path('migrations/2026_07_31_070150_backfill_initial_inventory_and_skus.php');
        try {
            $migration->up();
            $this->fail('El backfill debía abortar.');
        } catch (\RuntimeException) {
        }
        $this->assertNull(\DB::table('products')->where('id', $product->id)->value('sku'));
        $this->assertFalse(WarehouseInventory::where(['warehouse_id' => $warehouse->id, 'product_id' => $product->id])->exists());
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function warehouse(): Warehouse
    {
        return Warehouse::where('code', Warehouse::INITIAL_CODE)->firstOrFail();
    }

    private function product(array $attributes = []): Product
    {
        $stock = (int) ($attributes['stock'] ?? 0);
        unset($attributes['stock']);
        $product = Product::create(array_merge(['name' => 'Producto '.Str::random(6), 'slug' => 'producto-'.Str::uuid(), 'sku' => 'SKU-'.Str::random(8), 'price' => 10, 'is_active' => true], $attributes));
        $inventory = app(InventoryService::class);
        $inventory->initializeProduct($product,$stock,$stock > 0 ? $inventory->defaultWarehouse() : null);

        return $product->refresh();
    }

    private function branchPayload(string $code): array
    {
        return ['code' => $code, 'name' => 'Sede '.$code, 'address' => 'Av. Prueba 123', 'department' => 'Lima', 'province' => 'Lima', 'district' => 'Miraflores', 'allows_pickup' => false, 'serves_public' => false, 'is_active' => true];
    }

    private function orderPayload(Product $product,int $quantity): array
    {
        return ['shipping_info' => ['address' => 'Av. Prueba 123', 'city' => 'Lima'], 'payment_method' => 'transferencia', 'delivery_type' => 'delivery', 'items' => [['product_id' => $product->id, 'quantity' => $quantity]]];
    }
}
