<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\InventoryMovement;
use App\Models\InventoryReceipt;
use App\Models\InventoryReservation;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseInventory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

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

    public function test_csv_and_xlsx_import_preview_validate_rows_and_preserve_text_sku_leading_zeroes(): void
    {
        Sanctum::actingAs($this->admin());
        [$warehouse, $csvProduct] = $this->inventoryFixture(12);
        $xlsxProduct = Product::create(['name' => 'SKU con ceros', 'slug' => 'producto-'.Str::uuid(), 'sku' => '000123', 'price' => 10]);
        $xlsxProduct->forceFill(['stock' => 8])->save();
        WarehouseInventory::create(['warehouse_id' => $warehouse->id, 'product_id' => $xlsxProduct->id, 'quantity' => 8, 'reserved_quantity' => 0]);

        $csv = $this->post('/api/v1/admin/inventory-receipts/import-preview', [
            'warehouse_id' => $warehouse->id,
            'existing_lines' => '[]',
            'file' => UploadedFile::fake()->createWithContent('recepcion.csv', "\xEF\xBB\xBFsku;cantidad\r\n{$csvProduct->sku};3\r\n"),
        ], ['Accept' => 'application/json'])->assertOk();
        $csv->assertJsonPath('can_import', true)
            ->assertJsonPath('rows.0.row', 2)
            ->assertJsonPath('rows.0.sku', $csvProduct->sku)
            ->assertJsonPath('rows.0.quantity', 3)
            ->assertJsonPath('totals.total_products', 1)
            ->assertJsonPath('totals.total_units', 3);

        $xlsx = $this->post('/api/v1/admin/inventory-receipts/import-preview', [
            'warehouse_id' => $warehouse->id,
            'existing_lines' => '[]',
            'file' => $this->xlsxFile([['000123', '4']]),
        ], ['Accept' => 'application/json'])->assertOk();
        $xlsx->assertJsonPath('can_import', true)
            ->assertJsonPath('rows.0.sku', '000123')
            ->assertJsonPath('rows.0.product_id', $xlsxProduct->id)
            ->assertJsonPath('rows.0.quantity', 4);

        $this->assertSame(12, WarehouseInventory::query()->where('warehouse_id', $warehouse->id)->where('product_id', $csvProduct->id)->value('quantity'));
        $this->assertSame(8, WarehouseInventory::query()->where('warehouse_id', $warehouse->id)->where('product_id', $xlsxProduct->id)->value('quantity'));
        $this->assertDatabaseCount('inventory_receipts', 0);
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_corrupt_xlsx_returns_a_validation_error_without_changing_inventory(): void
    {
        Sanctum::actingAs($this->admin());
        [$warehouse, $product] = $this->inventoryFixture(20, 3);

        $this->post('/api/v1/admin/inventory-receipts/import-preview', [
            'warehouse_id' => $warehouse->id,
            'existing_lines' => '[]',
            'file' => UploadedFile::fake()->createWithContent('corrupt.xlsx', 'not an XLSX archive'),
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonPath('can_import', false)
            ->assertJsonPath('global_errors.0', 'El archivo XLSX no es un libro válido.');

        $this->assertSame(20, WarehouseInventory::query()->where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->value('quantity'));
        $this->assertSame(3, WarehouseInventory::query()->where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->value('reserved_quantity'));
        $this->assertSame(0, InventoryReceipt::query()->count());
        $this->assertSame(0, InventoryMovement::query()->count());
    }

    public function test_import_preview_reports_row_errors_for_unknown_duplicate_existing_and_invalid_quantities(): void
    {
        Sanctum::actingAs($this->admin());
        [$warehouse, $existingProduct] = $this->inventoryFixture();
        $duplicateProduct = Product::create(['name' => 'Duplicado import', 'slug' => 'producto-'.Str::uuid(), 'sku' => 'DUP-IMPORT', 'price' => 10]);
        $validProduct = Product::create(['name' => 'Válido import', 'slug' => 'producto-'.Str::uuid(), 'sku' => 'VALID-IMPORT', 'price' => 10]);
        $fractionalProduct = Product::create(['name' => 'Cantidad decimal', 'slug' => 'producto-'.Str::uuid(), 'sku' => 'DECIMAL-IMPORT', 'price' => 10]);
        $csv = implode("\n", [
            'sku,cantidad',
            $existingProduct->sku.',2',
            $duplicateProduct->sku.',1',
            $duplicateProduct->sku.',3',
            'NO-EXISTE,1',
            $validProduct->sku.',0',
            $fractionalProduct->sku.',1.5',
        ]);

        $response = $this->post('/api/v1/admin/inventory-receipts/import-preview', [
            'warehouse_id' => $warehouse->id,
            'existing_lines' => json_encode([['product_id' => $existingProduct->id, 'quantity' => 4]]),
            'file' => UploadedFile::fake()->createWithContent('errores.csv', $csv),
        ], ['Accept' => 'application/json'])->assertOk();

        $response->assertJsonPath('can_import', false)
            ->assertJsonPath('rows.0.row', 2)
            ->assertJsonPath('rows.0.errors.0', 'El producto ya está en las líneas del borrador.')
            ->assertJsonPath('rows.2.row', 4)
            ->assertJsonPath('rows.2.errors.0', 'El SKU está duplicado en el archivo.')
            ->assertJsonPath('rows.3.row', 5)
            ->assertJsonPath('rows.3.errors.0', 'No existe un producto activo con este SKU.')
            ->assertJsonPath('rows.4.row', 6)
            ->assertJsonPath('rows.4.errors.0', 'La cantidad debe ser un entero positivo.')
            ->assertJsonPath('rows.5.row', 7)
            ->assertJsonPath('rows.5.errors.0', 'La cantidad debe ser un entero positivo.');
        $this->assertDatabaseCount('inventory_receipts', 0);
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_import_preview_counts_existing_lines_for_combined_product_and_unit_limits(): void
    {
        Sanctum::actingAs($this->admin());
        [$warehouse, $existingProduct] = $this->inventoryFixture();
        $importProduct = Product::create(['name' => 'Import límite', 'slug' => 'producto-'.Str::uuid(), 'sku' => 'LIMIT-IMPORT', 'price' => 10]);
        $existingLines = [];
        $productIds = [$existingProduct->id];
        for ($index = 1; $index < 100; $index++) {
            $product = Product::create(['name' => 'Producto límite '.$index, 'slug' => 'producto-'.Str::uuid(), 'sku' => 'LIMIT-'.Str::upper(Str::random(8)), 'price' => 10]);
            $productIds[] = $product->id;
        }
        foreach ($productIds as $productId) {
            $existingLines[] = ['product_id' => $productId, 'quantity' => 1];
        }

        $lineLimit = $this->post('/api/v1/admin/inventory-receipts/import-preview', [
            'warehouse_id' => $warehouse->id,
            'existing_lines' => json_encode($existingLines),
            'file' => UploadedFile::fake()->createWithContent('lineas.csv', "sku,cantidad\n{$importProduct->sku},1\n"),
        ], ['Accept' => 'application/json'])->assertOk();
        $lineLimit->assertJsonPath('can_import', false)
            ->assertJsonPath('rows.0.errors.0', 'La importación supera el límite combinado de 100 productos.');

        $unitLimit = $this->post('/api/v1/admin/inventory-receipts/import-preview', [
            'warehouse_id' => $warehouse->id,
            'existing_lines' => json_encode([['product_id' => $existingProduct->id, 'quantity' => 99999]]),
            'file' => UploadedFile::fake()->createWithContent('unidades.csv', "sku,cantidad\n{$importProduct->sku},2\n"),
        ], ['Accept' => 'application/json'])->assertOk();
        $unitLimit->assertJsonPath('can_import', false)
            ->assertJsonPath('rows.0.errors.0', 'La importación supera el límite combinado de 100 000 unidades.');
        $this->assertDatabaseCount('inventory_receipts', 0);
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_import_to_confirmation_changes_stock_only_when_confirmed_and_creates_one_movement_per_line(): void
    {
        Sanctum::actingAs($admin = $this->admin());
        [$warehouse, $firstProduct] = $this->inventoryFixture(20, 4);
        $this->reservationFixture($admin, $warehouse, $firstProduct, 4);
        $secondProduct = Product::create(['name' => 'Segundo importado', 'slug' => 'producto-'.Str::uuid(), 'sku' => 'SECOND-IMPORT', 'price' => 10]);
        $secondProduct->forceFill(['stock' => 10])->save();
        WarehouseInventory::create(['warehouse_id' => $warehouse->id, 'product_id' => $secondProduct->id, 'quantity' => 10, 'reserved_quantity' => 2]);
        $csv = "sku;cantidad\r\n{$firstProduct->sku};7\r\n{$secondProduct->sku};5\r\n";

        $preview = $this->post('/api/v1/admin/inventory-receipts/import-preview', [
            'warehouse_id' => $warehouse->id,
            'existing_lines' => '[]',
            'file' => UploadedFile::fake()->createWithContent('confirmar.csv', $csv),
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('can_import', true);

        $this->assertSame(20, WarehouseInventory::query()->where('warehouse_id', $warehouse->id)->where('product_id', $firstProduct->id)->value('quantity'));
        $this->assertSame(10, WarehouseInventory::query()->where('warehouse_id', $warehouse->id)->where('product_id', $secondProduct->id)->value('quantity'));
        $this->assertDatabaseCount('inventory_movements', 0);

        $lines = array_map(fn (array $row) => ['product_id' => $row['product_id'], 'quantity' => $row['quantity']], $preview->json('rows'));
        $created = $this->postJson('/api/v1/admin/inventory-receipts', ['warehouse_id' => $warehouse->id, 'lines' => $lines])->assertCreated();
        $receiptId = (int) $created->json('data.id');
        $this->assertSame(20, WarehouseInventory::query()->where('warehouse_id', $warehouse->id)->where('product_id', $firstProduct->id)->value('quantity'));
        $this->assertSame(0, InventoryMovement::query()->count());

        $confirmed = $this->postJson('/api/v1/admin/inventory-receipts/'.$receiptId.'/confirm', ['version' => 1])->assertOk();
        $this->assertSame(27, WarehouseInventory::query()->where('warehouse_id', $warehouse->id)->where('product_id', $firstProduct->id)->value('quantity'));
        $this->assertSame(15, WarehouseInventory::query()->where('warehouse_id', $warehouse->id)->where('product_id', $secondProduct->id)->value('quantity'));
        $this->assertSame(4, WarehouseInventory::query()->where('warehouse_id', $warehouse->id)->where('product_id', $firstProduct->id)->value('reserved_quantity'));
        $this->assertSame(2, WarehouseInventory::query()->where('warehouse_id', $warehouse->id)->where('product_id', $secondProduct->id)->value('reserved_quantity'));
        $this->assertSame(27, $firstProduct->fresh()->stock);
        $this->assertSame(15, $secondProduct->fresh()->stock);
        $this->assertSame(2, InventoryMovement::query()->where('reference_type', 'inventory_receipt_line')->where('type', InventoryMovement::RECEIPT)->count());
        $this->assertDatabaseCount('inventory_reservations', 1);
        $this->assertSame('confirmed', $confirmed->json('data.status'));
    }

    public function test_import_template_has_the_expected_csv_headers(): void
    {
        Sanctum::actingAs($this->admin());

        $this->get('/api/v1/admin/inventory-receipts/import-template')
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8')
            ->assertHeader('content-disposition', 'attachment; filename=plantilla-recepcion-inventario.csv')
            ->assertSee('sku,cantidad');
    }

    public function test_failed_intermediate_movement_rolls_back_and_receipt_can_be_retried(): void
    {
        Sanctum::actingAs($admin = $this->admin());
        [$warehouse, $firstProduct] = $this->inventoryFixture(10, 2);
        $secondProduct = Product::create(['name' => 'Segundo producto', 'slug' => 'producto-'.Str::uuid(), 'sku' => 'SKU-'.Str::upper(Str::random(8)), 'price' => 10]);
        $overflowStock = (int) config('inventory_receipts.max_product_stock');
        $secondProduct->forceFill(['stock' => $overflowStock])->save();
        WarehouseInventory::create(['warehouse_id' => $warehouse->id, 'product_id' => $secondProduct->id, 'quantity' => $overflowStock, 'reserved_quantity' => 0]);
        $receipt = $this->draft($warehouse, [[$firstProduct, 5], [$secondProduct, 5]], $admin);

        $failed = false;
        try {
            app(\App\Services\InventoryReceiptService::class)->confirm($receipt->id, $receipt->version, $admin);
        } catch (\Throwable) {
            // The second line exceeds the aggregate stock limit after the first line was applied.
            $failed = true;
        }

        $this->assertTrue($failed, 'El desbordamiento de la segunda línea debe abortar la recepción.');
        $this->assertSame('draft', $receipt->fresh()->status);
        $this->assertSame(10, WarehouseInventory::query()->where('warehouse_id', $warehouse->id)->where('product_id', $firstProduct->id)->value('quantity'));
        $this->assertSame($overflowStock, WarehouseInventory::query()->where('warehouse_id', $warehouse->id)->where('product_id', $secondProduct->id)->value('quantity'));
        $this->assertSame(10, $firstProduct->fresh()->stock);
        $this->assertSame($overflowStock, $secondProduct->fresh()->stock);
        $this->assertDatabaseCount('inventory_movements', 0);

        $secondProduct->forceFill(['stock' => 10])->save();
        WarehouseInventory::query()->where('warehouse_id', $warehouse->id)->where('product_id', $secondProduct->id)->update(['quantity' => 10]);
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
        $this->postJson('/api/v1/admin/inventory-receipts/resolve-product-code', ['code' => 'SKU-TEST'])->assertUnauthorized();
        Sanctum::actingAs(User::factory()->create(['role' => 'customer', 'is_active' => true]));
        $this->getJson('/api/v1/admin/inventory-receipts')->assertForbidden();
        $this->postJson('/api/v1/admin/inventory-receipts/resolve-product-code', ['code' => 'SKU-TEST'])->assertForbidden();

        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'is_active' => false]));
        $this->getJson('/api/v1/admin/inventory-receipts')->assertForbidden();
        $this->postJson('/api/v1/admin/inventory-receipts/resolve-product-code', ['code' => 'SKU-TEST'])->assertForbidden();
    }

    public function test_admin_resolves_internal_product_qr_and_exact_sku_without_exposing_inventory_data(): void
    {
        Sanctum::actingAs($this->admin());
        [$warehouse, $product] = $this->inventoryFixture(12, 3);
        $movementCount = InventoryMovement::query()->count();
        $receiptCount = InventoryReceipt::query()->count();

        $this->postJson('/api/v1/admin/inventory-receipts/resolve-product-code', ['code' => 'LS1:PRODUCT:'.$product->id])
            ->assertOk()
            ->assertExactJson(['data' => ['id' => $product->id, 'name' => $product->name, 'sku' => $product->sku, 'image_url' => null, 'matched_code' => 'LS1:PRODUCT:'.$product->id, 'matched_format' => 'qr_internal']]);
        $this->postJson('/api/v1/admin/inventory-receipts/resolve-product-code', ['code' => strtolower($product->sku)])
            ->assertOk()
            ->assertExactJson(['data' => ['id' => $product->id, 'name' => $product->name, 'sku' => $product->sku, 'image_url' => null, 'matched_code' => strtolower($product->sku), 'matched_format' => 'sku']]);
        $this->postJson('/api/v1/admin/inventory-receipts/resolve-product-code', ['code' => 'https://example.test/'.$product->id])->assertUnprocessable();
        $this->postJson('/api/v1/admin/inventory-receipts/resolve-product-code', ['code' => 'LS1:PRODUCT:9999999999'])->assertNotFound();
        $this->postJson('/api/v1/admin/inventory-receipts/resolve-product-code', ['code' => str_repeat('X', 121)])->assertUnprocessable();

        $product->forceFill(['is_active' => false])->save();
        $this->postJson('/api/v1/admin/inventory-receipts/resolve-product-code', ['code' => 'LS1:PRODUCT:'.$product->id])->assertNotFound();
        $this->assertSame(12, WarehouseInventory::query()->where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->value('quantity'));
        $this->assertSame(3, WarehouseInventory::query()->where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->value('reserved_quantity'));
        $this->assertSame($movementCount, InventoryMovement::query()->count());
        $this->assertSame($receiptCount, InventoryReceipt::query()->count());
    }

    public function test_confirmation_rejects_physical_and_aggregate_stock_overflow_atomically(): void
    {
        Sanctum::actingAs($admin = $this->admin());
        [$warehouse, $physicalOverflow] = $this->inventoryFixture(4294967295, 0, 2147483647);
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

    private function xlsxFile(array $rows): UploadedFile
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'inventory-receipt-'.Str::uuid().'.zip';
        $shared = ['sku', 'cantidad'];
        foreach ($rows as [$sku]) {
            $shared[] = (string) $sku;
        }
        $sharedXml = '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="'.count($shared).'" uniqueCount="'.count($shared).'">'
            .implode('', array_map(fn (string $value) => '<si><t>'.htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</t></si>', $shared))
            .'</sst>';
        $sheetRows = '<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c></row>';
        foreach (array_values($rows) as $index => [$sku, $quantity]) {
            $rowNumber = $index + 2;
            $skuIndex = $index + 2;
            $sheetRows .= '<row r="'.$rowNumber.'"><c r="A'.$rowNumber.'" t="s"><v>'.$skuIndex.'</v></c><c r="B'.$rowNumber.'"><v>'.htmlspecialchars((string) $quantity, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</v></c></row>';
        }
        $archive = new \PharData($path, 0, null, \Phar::ZIP);
        $archive['[Content_Types].xml'] = '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/></Types>';
        $archive['_rels/.rels'] = '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>';
        $archive['xl/workbook.xml'] = '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Recepción" sheetId="1" r:id="rId1"/></sheets></workbook>';
        $archive['xl/_rels/workbook.xml.rels'] = '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/></Relationships>';
        $archive['xl/sharedStrings.xml'] = '<?xml version="1.0" encoding="UTF-8"?>'.$sharedXml;
        $archive['xl/worksheets/sheet1.xml'] = '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.$sheetRows.'</sheetData></worksheet>';
        unset($archive);
        $contents = file_get_contents($path);
        unlink($path);

        return UploadedFile::fake()->createWithContent('recepcion.xlsx', $contents);
    }

    private function inventoryFixture(int $physical = 10, int $reserved = 0, ?int $productStock = null): array
    {
        $branch = Branch::create(['code' => 'B-'.Str::upper(Str::random(8)), 'name' => 'Sede de pruebas', 'address' => 'Lima', 'is_active' => true]);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'code' => 'W-'.Str::upper(Str::random(8)), 'name' => 'Almacén de pruebas', 'is_active' => true]);
        $product = Product::create(['name' => 'Producto de prueba', 'slug' => 'producto-'.Str::uuid(), 'sku' => 'SKU-'.Str::upper(Str::random(8)), 'price' => 10]);
        $product->forceFill(['stock' => $productStock ?? $physical])->save();
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
