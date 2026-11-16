<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\InventoryMovement;
use App\Models\InventoryReceipt;
use App\Models\InventoryReservation;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseInventory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use Throwable;

class InventoryReceiptTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_confirm_and_retry_a_receipt_without_changing_reservations(): void
    {
        $admin = $this->admin();
        Sanctum::actingAs($admin);
        [$warehouse, $product] = $this->inventoryFixture(20, 4);
        $reservation = $this->reservationFixture($admin, $warehouse, $product, 4);
        $reservationBefore = $reservation->getRawOriginal();
        $payload = [
            'warehouse_id' => $warehouse->id,
            'external_reference' => 'PO-TEST-100',
            'notes' => 'Recepción validada',
            'lines' => [['product_id' => $product->id, 'quantity' => 7]],
        ];

        $created = $this->postJson('/api/v1/admin/inventory-receipts', $payload)->assertCreated();
        $this->assertSame('draft', $created->json('data.status'));
        $this->assertSame(1, $created->json('data.version'));
        $receiptId = (int) $created->json('data.id');

        $confirmed = $this->postJson('/api/v1/admin/inventory-receipts/'.$receiptId.'/confirm', ['version' => 1])->assertOk();
        $this->assertSame('confirmed', $confirmed->json('data.status'));
        $this->assertSame($admin->id, $confirmed->json('data.confirmed_by'));
        $confirmedAt = $confirmed->json('data.confirmed_at');
        $this->assertNotEmpty($confirmedAt);

        $balance = WarehouseInventory::query()->where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->firstOrFail();
        $this->assertSame(27, $balance->quantity);
        $this->assertSame(4, $balance->reserved_quantity);
        $this->assertSame(27, $product->fresh()->stock);
        $this->assertSame($reservationBefore, $reservation->fresh()->getRawOriginal());
        $this->assertDatabaseCount('inventory_reservations', 1);
        $this->assertDatabaseHas('inventory_movements', [
            'type' => InventoryMovement::RECEIPT,
            'reference_type' => 'inventory_receipt_line',
            'reference_id' => (string) $confirmed->json('data.lines.0.id'),
            'idempotency_key' => 'inventory-receipt-line-'.$confirmed->json('data.lines.0.id'),
            'quantity' => 7,
            'quantity_before' => 20,
            'quantity_after' => 27,
            'user_id' => $admin->id,
        ]);
        $this->getJson('/api/v1/admin/inventory-movements?type=receipt')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type', 'receipt');

        $this->postJson('/api/v1/admin/inventory-receipts/'.$receiptId.'/confirm', ['version' => 1])->assertOk()
            ->assertJsonPath('data.confirmed_by', $admin->id)
            ->assertJsonPath('data.confirmed_at', $confirmedAt);
        $this->assertSame(27, $balance->fresh()->quantity);
        $this->assertSame(27, $product->fresh()->stock);
        $this->assertSame(1, InventoryMovement::query()->where('reference_type', 'inventory_receipt_line')->count());
        $this->assertSame($reservationBefore, $reservation->fresh()->getRawOriginal());
    }

    public function test_receipt_validation_rejects_duplicate_products_inactive_warehouse_and_limits(): void
    {
        Sanctum::actingAs($this->admin());
        [$warehouse, $product] = $this->inventoryFixture();
        $base = ['warehouse_id' => $warehouse->id, 'lines' => [['product_id' => $product->id, 'quantity' => 1]]];

        $this->postJson('/api/v1/admin/inventory-receipts', array_merge($base, ['lines' => [
            ['product_id' => $product->id, 'quantity' => 1],
            ['product_id' => $product->id, 'quantity' => 2],
        ]]))->assertUnprocessable();
        $this->postJson('/api/v1/admin/inventory-receipts', array_merge($base, ['lines' => [
            ['product_id' => $product->id, 'quantity' => 100001],
        ]]))->assertUnprocessable();
        $warehouse->forceFill(['is_active' => false])->save();
        $this->postJson('/api/v1/admin/inventory-receipts', $base)->assertUnprocessable();
        $this->assertDatabaseCount('inventory_receipts', 0);

        [$activeWarehouse, $activeProduct] = $this->inventoryFixture();
        $draft = $this->draft($activeWarehouse, [[$activeProduct, 2]], User::where('role', 'admin')->firstOrFail());
        $activeWarehouse->forceFill(['is_active' => false])->save();
        $this->postJson('/api/v1/admin/inventory-receipts/'.$draft->id.'/confirm', ['version' => $draft->version])->assertUnprocessable();
        $this->assertSame('draft', $draft->fresh()->status);
        $this->assertDatabaseCount('inventory_movements', 0);

        [$branchWarehouse, $branchProduct] = $this->inventoryFixture();
        $branchDraft = $this->draft($branchWarehouse, [[$branchProduct, 2]], User::where('role', 'admin')->firstOrFail());
        $branchWarehouse->branch()->update(['is_active' => false]);
        $this->postJson('/api/v1/admin/inventory-receipts/'.$branchDraft->id.'/confirm', ['version' => $branchDraft->version])->assertUnprocessable();
        $this->assertSame('draft', $branchDraft->fresh()->status);
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_failed_intermediate_movement_rolls_back_and_receipt_can_be_retried(): void
    {
        Sanctum::actingAs($admin = $this->admin());
        [$warehouse, $firstProduct] = $this->inventoryFixture(10, 2);
        $secondProduct = Product::create(['name' => 'Segundo producto', 'slug' => 'producto-'.Str::uuid(), 'sku' => 'SKU-'.Str::upper(Str::random(8)), 'price' => 10]);
        $secondProduct->forceFill(['stock' => 10])->save();
        WarehouseInventory::create(['warehouse_id' => $warehouse->id, 'product_id' => $secondProduct->id, 'quantity' => 10, 'reserved_quantity' => 0]);
        $receipt = $this->draft($warehouse, [[$firstProduct, 5], [$secondProduct, 5]], $admin);
        DB::statement("CREATE TRIGGER reject_receipt_movement BEFORE INSERT ON inventory_movements WHEN NEW.type = 'receipt' AND NEW.product_id = {$secondProduct->id} BEGIN SELECT RAISE(ABORT, 'test failure'); END");

        $failed = false;
        try {
            try {
                app(\App\Services\InventoryReceiptService::class)->confirm($receipt->id, $receipt->version, $admin);
            } catch (Throwable) {
                // Failure injection is intentional; persisted state is asserted below.
                $failed = true;
            }
        } finally {
            DB::statement('DROP TRIGGER IF EXISTS reject_receipt_movement');
        }

        $this->assertTrue($failed, 'El trigger debe abortar la escritura intermedia del movimiento.');
        $this->assertSame('draft', $receipt->fresh()->status);
        $this->assertSame(10, WarehouseInventory::query()->where('warehouse_id', $warehouse->id)->where('product_id', $firstProduct->id)->value('quantity'));
        $this->assertSame(10, WarehouseInventory::query()->where('warehouse_id', $warehouse->id)->where('product_id', $secondProduct->id)->value('quantity'));
        $this->assertSame(10, $firstProduct->fresh()->stock);
        $this->assertSame(10, $secondProduct->fresh()->stock);
        $this->assertDatabaseCount('inventory_movements', 0);

        $confirmed = app(\App\Services\InventoryReceiptService::class)->confirm($receipt->id, $receipt->version, $admin);
        $this->assertSame('confirmed', $confirmed->status);
        $this->assertSame(15, WarehouseInventory::query()->where('warehouse_id', $warehouse->id)->where('product_id', $firstProduct->id)->value('quantity'));
        $this->assertSame(15, WarehouseInventory::query()->where('warehouse_id', $warehouse->id)->where('product_id', $secondProduct->id)->value('quantity'));
        $this->assertSame(15, $firstProduct->fresh()->stock);
        $this->assertSame(15, $secondProduct->fresh()->stock);
        $this->assertSame(2, InventoryMovement::query()->where('reference_type', 'inventory_receipt_line')->count());
    }

    public function test_confirmed_receipt_is_immutable_and_draft_requires_current_version(): void
    {
        Sanctum::actingAs($admin = $this->admin());
        [$warehouse, $product] = $this->inventoryFixture();
        $receipt = $this->draft($warehouse, [[$product, 2]], $admin);
        $this->putJson('/api/v1/admin/inventory-receipts/'.$receipt->id, [
            'version' => 9,
            'warehouse_id' => $warehouse->id,
            'lines' => [['product_id' => $product->id, 'quantity' => 3]],
        ])->assertConflict();
        $this->postJson('/api/v1/admin/inventory-receipts/'.$receipt->id.'/confirm', ['version' => 0])->assertUnprocessable();
        $this->putJson('/api/v1/admin/inventory-receipts/'.$receipt->id, [
            'version' => 1,
            'warehouse_id' => $warehouse->id,
            'lines' => [['product_id' => $product->id, 'quantity' => 3]],
        ])->assertOk();
        $this->postJson('/api/v1/admin/inventory-receipts/'.$receipt->id.'/confirm', ['version' => 1])->assertConflict();
        $this->assertSame('draft', $receipt->fresh()->status);
        $this->assertSame(2, $receipt->fresh()->version);
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->postJson('/api/v1/admin/inventory-receipts/'.$receipt->id.'/confirm', ['version' => 2])->assertOk();
        $this->putJson('/api/v1/admin/inventory-receipts/'.$receipt->id, [
            'version' => 3,
            'warehouse_id' => $warehouse->id,
            'lines' => [['product_id' => $product->id, 'quantity' => 3]],
        ])->assertConflict();
        $this->postJson('/api/v1/admin/inventory-receipts/'.$receipt->id.'/cancel', ['version' => 3])->assertConflict();
    }

    public function test_receipt_routes_require_an_active_administrator(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'customer', 'is_active' => true]));
        $this->getJson('/api/v1/admin/inventory-receipts')->assertForbidden();

        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'is_active' => false]));
        $this->getJson('/api/v1/admin/inventory-receipts')->assertForbidden();
    }

    public function test_confirmation_rejects_physical_and_aggregate_stock_overflow_atomically(): void
    {
        Sanctum::actingAs($admin = $this->admin());
        [$warehouse, $physicalOverflow] = $this->inventoryFixture(4294967295, 0);
        $physicalOverflow->forceFill(['stock' => 2147483647])->save();
        $physicalReceipt = $this->draft($warehouse, [[$physicalOverflow, 1]], $admin);
        $this->postJson('/api/v1/admin/inventory-receipts/'.$physicalReceipt->id.'/confirm', ['version' => 1])->assertUnprocessable();
        $this->assertSame('draft', $physicalReceipt->fresh()->status);
        $this->assertSame(4294967295, WarehouseInventory::query()->where('warehouse_id', $warehouse->id)->where('product_id', $physicalOverflow->id)->value('quantity'));
        $this->assertSame(2147483647, $physicalOverflow->fresh()->stock);
        $this->assertDatabaseCount('inventory_movements', 0);

        [$aggregateWarehouse, $aggregateOverflow] = $this->inventoryFixture(2147483647, 0);
        $aggregateReceipt = $this->draft($aggregateWarehouse, [[$aggregateOverflow, 1]], $admin);
        $this->postJson('/api/v1/admin/inventory-receipts/'.$aggregateReceipt->id.'/confirm', ['version' => 1])->assertUnprocessable();
        $this->assertSame('draft', $aggregateReceipt->fresh()->status);
        $this->assertSame(2147483647, WarehouseInventory::query()->where('warehouse_id', $aggregateWarehouse->id)->where('product_id', $aggregateOverflow->id)->value('quantity'));
        $this->assertSame(2147483647, $aggregateOverflow->fresh()->stock);
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_migration_rollback_refuses_to_drop_confirmed_receipt_movement_references(): void
    {
        $admin = $this->admin();
        [$warehouse, $product] = $this->inventoryFixture();
        $receipt = $this->draft($warehouse, [[$product, 2]], $admin);
        app(\App\Services\InventoryReceiptService::class)->confirm($receipt->id, $receipt->version, $admin);

        $lineMigration = require database_path('migrations/2026_11_16_070010_create_inventory_receipt_lines_table.php');
        $headerMigration = require database_path('migrations/2026_11_16_070000_create_inventory_receipts_table.php');
        $lineBlocked = false;
        $headerBlocked = false;
        try {
            $lineMigration->down();
        } catch (\RuntimeException) {
            $lineBlocked = true;
        }
        try {
            $headerMigration->down();
        } catch (\RuntimeException) {
            $headerBlocked = true;
        }

        $this->assertTrue($lineBlocked);
        $this->assertTrue($headerBlocked);
        $this->assertTrue(Schema::hasTable('inventory_receipt_lines'));
        $this->assertTrue(Schema::hasTable('inventory_receipts'));
        $this->assertDatabaseHas('inventory_movements', [
            'reference_type' => 'inventory_receipt_line',
            'reference_id' => (string) $receipt->lines()->value('id'),
        ]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'is_active' => true]);
    }

    private function inventoryFixture(int $physical = 10, int $reserved = 0): array
    {
        $branch = Branch::create(['code' => 'B-'.Str::upper(Str::random(8)), 'name' => 'Sede de pruebas', 'address' => 'Lima', 'is_active' => true]);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'code' => 'W-'.Str::upper(Str::random(8)), 'name' => 'Almacén de pruebas', 'is_active' => true]);
        $product = Product::create(['name' => 'Producto de prueba', 'slug' => 'producto-'.Str::uuid(), 'sku' => 'SKU-'.Str::upper(Str::random(8)), 'price' => 10]);
        $product->forceFill(['stock' => $physical])->save();
        WarehouseInventory::create(['warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'quantity' => $physical, 'reserved_quantity' => $reserved]);

        return [$warehouse, $product];
    }

    private function reservationFixture(User $user, Warehouse $warehouse, Product $product, int $quantity): InventoryReservation
    {
        $orderId = DB::table('orders')->insertGetId([
            'user_id' => $user->id,
            'status' => 'pending',
            'total' => '10.00',
            'shipping_info' => json_encode(['address' => 'Sanitizado']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $itemId = DB::table('order_items')->insertGetId([
            'order_id' => $orderId,
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => $quantity,
            'price' => '10.00',
            'subtotal' => (string) ($quantity * 10).'.00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('inventory_reservations')->insert([
            'order_id' => $orderId,
            'order_item_id' => $itemId,
            'reservation_sequence' => 1,
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => $quantity,
            'status' => 'active',
            'expires_at' => now()->addHour(),
            'idempotency_key' => 'test-reservation-'.$itemId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return InventoryReservation::query()->where('order_item_id', $itemId)->firstOrFail();
    }

    private function draft(Warehouse $warehouse, array $productQuantities, User $admin): InventoryReceipt
    {
        $lines = collect($productQuantities)->map(fn (array $item) => ['product_id' => $item[0]->id, 'quantity' => $item[1]])->all();

        return app(\App\Services\InventoryReceiptService::class)->createDraft([
            'warehouse_id' => $warehouse->id,
            'lines' => $lines,
        ], $admin);
    }
}
