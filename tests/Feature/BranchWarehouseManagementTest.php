<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseInventory;
use App\Services\InventoryService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BranchWarehouseManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_branch_becomes_main_and_second_branch_does_not(): void
    {
        Warehouse::query()->delete();
        Branch::query()->delete();
        Sanctum::actingAs($this->admin());

        $firstId = $this->postJson('/api/v1/admin/branches', $this->branchPayload('NORTE'))
            ->assertCreated()->assertJsonPath('is_main', true)->json('id');
        $secondId = $this->postJson('/api/v1/admin/branches', $this->branchPayload('SUR'))
            ->assertCreated()->assertJsonPath('is_main', false)->json('id');

        $this->assertTrue(Branch::findOrFail($firstId)->is_main);
        $this->assertFalse(Branch::findOrFail($secondId)->is_main);
        $this->assertSame(1, Branch::where('is_main', true)->count());
    }

    public function test_changing_main_branch_unmarks_previous_without_changing_web_warehouse(): void
    {
        Sanctum::actingAs($this->admin());
        $originalBranch = Branch::where('is_main', true)->firstOrFail();
        $webWarehouse = app(InventoryService::class)->defaultWarehouse();
        $secondaryId = $this->postJson('/api/v1/admin/branches', $this->branchPayload('SECUNDARIA'))->assertCreated()->json('id');

        $this->patchJson("/api/v1/admin/branches/{$secondaryId}/main")
            ->assertOk()->assertJsonPath('is_main', true);

        $this->assertFalse($originalBranch->refresh()->is_main);
        $this->assertTrue(Branch::findOrFail($secondaryId)->is_main);
        $this->assertSame($webWarehouse->id, app(InventoryService::class)->defaultWarehouse()->id);

        $this->patchJson("/api/v1/admin/branches/{$secondaryId}/main")->assertOk()->assertJsonPath('is_main', true);
        $this->assertSame(1, Branch::where('is_main', true)->count());
    }

    public function test_database_guard_prevents_two_main_branches(): void
    {
        $this->expectException(QueryException::class);
        Branch::create($this->branchPayload('DUPLICADA') + ['is_main' => true]);
    }

    public function test_main_branch_cannot_be_deactivated_and_branch_with_warehouses_cannot_be_deleted(): void
    {
        Sanctum::actingAs($this->admin());
        $branch = Branch::where('is_main', true)->firstOrFail();

        $this->patchJson("/api/v1/admin/branches/{$branch->id}/status", ['is_active' => false])
            ->assertUnprocessable()->assertJsonValidationErrors('is_active');
        $this->deleteJson("/api/v1/admin/branches/{$branch->id}")
            ->assertUnprocessable()->assertJsonValidationErrors('branch');
    }

    public function test_warehouse_requires_an_active_branch_and_api_returns_real_branch(): void
    {
        Sanctum::actingAs($this->admin());
        $inactive = Branch::create(array_merge($this->branchPayload('INACTIVA'), ['is_active' => false]));
        $payload = $this->warehousePayload($inactive->id, 'ALM-INACTIVO');

        $this->postJson('/api/v1/admin/warehouses', $payload)->assertUnprocessable()->assertJsonValidationErrors('branch_id');
        $this->postJson('/api/v1/admin/warehouses', array_merge($payload, ['branch_id' => 999999]))->assertUnprocessable()->assertJsonValidationErrors('branch_id');

        $active = Branch::create($this->branchPayload('CALLAO'));
        $response = $this->postJson('/api/v1/admin/warehouses', $this->warehousePayload($active->id, 'ALM-CALLAO'))
            ->assertCreated()
            ->assertJsonPath('branch.id', $active->id)
            ->assertJsonPath('branch.name', 'Sede CALLAO')
            ->assertJsonPath('branch.district', 'Miraflores');

        $this->getJson('/api/v1/admin/warehouses?branch_id='.$active->id)
            ->assertOk()->assertJsonPath('data.0.id', $response->json('id'))
            ->assertJsonPath('data.0.branch.name', 'Sede CALLAO');
    }

    public function test_warehouse_can_change_branch_without_changing_inventory(): void
    {
        Sanctum::actingAs($this->admin());
        $warehouse = app(InventoryService::class)->defaultWarehouse();
        $destination = Branch::create($this->branchPayload('DESTINO'));
        $product = Product::create([
            'name' => 'Producto traslado de sede', 'slug' => 'producto-'.Str::uuid(),
            'sku' => 'BR-'.Str::upper(Str::random(8)), 'price' => 10, 'is_active' => true,
        ]);
        WarehouseInventory::create(['warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'quantity' => 7]);

        $this->putJson("/api/v1/admin/warehouses/{$warehouse->id}", [
            'branch_id' => $destination->id, 'code' => $warehouse->code, 'name' => $warehouse->name,
            'description' => $warehouse->description, 'address' => $warehouse->address,
            'is_default' => true, 'is_active' => true,
        ])->assertOk()->assertJsonPath('branch.id', $destination->id);

        $this->assertSame(7, (int) WarehouseInventory::where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->value('quantity'));
        $this->assertSame(0, $product->refresh()->stock);
    }

    public function test_changing_web_warehouse_does_not_change_main_branch_and_only_one_default_remains(): void
    {
        Sanctum::actingAs($this->admin());
        $mainBranch = Branch::where('is_main', true)->firstOrFail();
        $oldDefault = app(InventoryService::class)->defaultWarehouse();
        $secondaryBranch = Branch::create($this->branchPayload('ORIENTE'));

        $newId = $this->postJson('/api/v1/admin/warehouses', $this->warehousePayload($secondaryBranch->id, 'ALM-ORIENTE', true))
            ->assertCreated()->assertJsonPath('is_default', true)->json('id');

        $this->assertFalse($oldDefault->refresh()->is_default);
        $this->assertSame($newId, app(InventoryService::class)->defaultWarehouse()->id);
        $this->assertSame(1, Warehouse::where('is_default', true)->count());
        $this->assertTrue($mainBranch->refresh()->is_main);
    }

    public function test_database_guard_prevents_two_web_default_warehouses(): void
    {
        $branch = Branch::where('is_active', true)->firstOrFail();
        $this->expectException(QueryException::class);
        Warehouse::create($this->warehousePayload($branch->id, 'ALM-DUPLICADO', true));
    }

    public function test_web_reservation_continues_using_only_global_default_warehouse(): void
    {
        $admin = $this->admin();
        Sanctum::actingAs($admin);
        $branch = Branch::create($this->branchPayload('VENTA-WEB'));
        $warehouseId = $this->postJson('/api/v1/admin/warehouses', $this->warehousePayload($branch->id, 'ALM-VENTA-WEB', true))->assertCreated()->json('id');
        $warehouse = Warehouse::findOrFail($warehouseId);
        $product = Product::create([
            'name' => 'Producto reserva sede', 'slug' => 'reserva-'.Str::uuid(),
            'sku' => 'RS-'.Str::upper(Str::random(8)), 'price' => 25, 'is_active' => true,
        ]);
        app(InventoryService::class)->initializeProduct($product, 3, $warehouse, $admin);

        Sanctum::actingAs(User::factory()->create(['role' => 'customer']));
        $orderId = $this->postJson('/api/v1/orders', [
            'shipping_info' => ['address' => 'Av. Cliente 123', 'city' => 'Lima'],
            'payment_method' => 'transferencia', 'delivery_type' => 'delivery',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertCreated()->json('id');

        $this->assertDatabaseHas('order_items', ['order_id' => $orderId, 'warehouse_id' => $warehouseId]);
        $this->assertDatabaseHas('inventory_reservations', ['order_id' => $orderId, 'warehouse_id' => $warehouseId, 'status' => 'active']);
    }

    public function test_customer_cannot_manage_branches(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'customer']));
        $branch = Branch::firstOrFail();

        $this->getJson('/api/v1/admin/branches')->assertForbidden();
        $this->postJson('/api/v1/admin/branches', $this->branchPayload('PROHIBIDA'))->assertForbidden();
        $this->patchJson("/api/v1/admin/branches/{$branch->id}/main")->assertForbidden();
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function branchPayload(string $code): array
    {
        return [
            'code' => $code, 'name' => 'Sede '.$code, 'address' => 'Av. Real 123',
            'department' => 'Lima', 'province' => 'Lima', 'district' => 'Miraflores',
            'reference' => null, 'phone' => null, 'email' => null, 'business_hours' => null,
            'pickup_instructions' => null, 'description' => null, 'allows_pickup' => true,
            'serves_public' => true, 'is_active' => true,
        ];
    }

    private function warehousePayload(int $branchId, string $code, bool $default = false): array
    {
        return [
            'branch_id' => $branchId, 'code' => $code, 'name' => 'Almacén '.$code,
            'description' => null, 'address' => null, 'is_default' => $default, 'is_active' => true,
        ];
    }
}
