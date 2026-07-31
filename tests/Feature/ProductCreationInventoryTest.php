<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseInventory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductCreationInventoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_creates_product_with_zero_quantity_without_inventory_or_movement(): void
    {
        Sanctum::actingAs($this->admin());
        $warehouse=$this->principalWarehouse();
        $response=$this->postJson('/api/v1/admin/products',$this->payload(0,$warehouse->id))->assertCreated();
        $productId=$response->json('id');

        $this->assertDatabaseHas('products',['id'=>$productId,'stock'=>0]);
        $this->assertDatabaseMissing('warehouse_inventories',['product_id'=>$productId]);
        $this->assertDatabaseMissing('inventory_movements',['product_id'=>$productId]);
    }

    public function test_positive_quantity_is_recorded_in_selected_active_warehouse(): void
    {
        $admin=$this->admin();Sanctum::actingAs($admin);
        $selected=Warehouse::create(['branch_id'=>Branch::where('code','PRINCIPAL')->value('id'),'code'=>'SELECTED','name'=>'Seleccionado','is_default'=>false,'is_active'=>true]);
        $response=$this->postJson('/api/v1/admin/products',$this->payload(7,$selected->id))->assertCreated();
        $productId=$response->json('id');

        $this->assertDatabaseHas('warehouse_inventories',['warehouse_id'=>$selected->id,'product_id'=>$productId,'quantity'=>7]);
        $this->assertDatabaseHas('inventory_movements',['warehouse_id'=>$selected->id,'product_id'=>$productId,'user_id'=>$admin->id,'type'=>InventoryMovement::INITIAL,'quantity'=>7,'quantity_before'=>0,'quantity_after'=>7,'reason'=>'Stock inicial del producto','idempotency_key'=>'initial-product-'.$productId]);
        $this->assertSame(7,Product::findOrFail($productId)->stock);
        $this->assertSame(7,(int)WarehouseInventory::where('product_id',$productId)->sum('quantity'));
    }

    public function test_nonexistent_or_inactive_warehouse_is_rejected(): void
    {
        Sanctum::actingAs($this->admin());
        $this->postJson('/api/v1/admin/products',$this->payload(2,999999))->assertUnprocessable();
        $inactive=Warehouse::create(['branch_id'=>Branch::where('code','PRINCIPAL')->value('id'),'code'=>'INACTIVE','name'=>'Inactivo','is_default'=>false,'is_active'=>false]);
        $this->postJson('/api/v1/admin/products',$this->payload(2,$inactive->id))->assertUnprocessable();
        $this->assertDatabaseMissing('products',['name'=>'Producto desde formulario']);
    }

    public function test_warehouse_is_required_when_initial_quantity_is_positive(): void
    {
        Sanctum::actingAs($this->admin());
        $this->postJson('/api/v1/admin/products',$this->payload(3,null))->assertUnprocessable()->assertJsonValidationErrors('warehouse_id');
        $this->assertDatabaseMissing('products',['name'=>'Producto desde formulario']);
    }

    public function test_inventory_failure_rolls_back_product_creation(): void
    {
        Sanctum::actingAs($this->admin());
        $inactiveBranch=Branch::create(['code'=>'INACTIVE-BRANCH','name'=>'Sede inactiva','address'=>'Av. Prueba','is_active'=>false]);
        $warehouse=Warehouse::create(['branch_id'=>$inactiveBranch->id,'code'=>'ACTIVE-WH','name'=>'Almacén con sede inactiva','is_default'=>false,'is_active'=>true]);

        $this->postJson('/api/v1/admin/products',$this->payload(4,$warehouse->id))->assertUnprocessable();
        $this->assertDatabaseMissing('products',['name'=>'Producto desde formulario']);
        $this->assertDatabaseCount('warehouse_inventories',0);
        $this->assertDatabaseCount('inventory_movements',0);
    }

    public function test_customer_cannot_create_product_or_initial_inventory(): void
    {
        Sanctum::actingAs(User::factory()->create(['role'=>'customer']));
        $this->postJson('/api/v1/admin/products',$this->payload(5,$this->principalWarehouse()->id))->assertForbidden();
        $this->assertDatabaseMissing('products',['name'=>'Producto desde formulario']);
    }

    public function test_product_edit_ignores_inventory_creation_fields(): void
    {
        $admin=$this->admin();Sanctum::actingAs($admin);
        $product=Product::create(['name'=>'Producto editable','slug'=>'producto-editable','sku'=>'EDITABLE','price'=>10,'is_active'=>true]);
        $warehouse=$this->principalWarehouse();
        $this->putJson('/api/v1/admin/products/'.$product->id,['name'=>'Producto editado','cantidad_inicial'=>99,'warehouse_id'=>$warehouse->id,'stock'=>99])->assertOk();
        $this->assertSame(0,$product->refresh()->stock);
        $this->assertDatabaseMissing('warehouse_inventories',['product_id'=>$product->id]);
        $this->assertDatabaseMissing('inventory_movements',['product_id'=>$product->id]);
    }

    public function test_warehouse_options_include_only_active_operational_warehouses_and_prefer_principal(): void
    {
        $branch=Branch::where('code','PRINCIPAL')->firstOrFail();
        Warehouse::create(['branch_id'=>$branch->id,'code'=>'INACTIVE-OPTION','name'=>'No visible','is_default'=>false,'is_active'=>false]);
        Sanctum::actingAs($this->admin());
        $response=$this->getJson('/api/v1/admin/warehouses/options')->assertOk();
        $this->assertSame('ALM-PRINCIPAL',$response->json('0.code'));
        $this->assertNotContains('INACTIVE-OPTION',collect($response->json())->pluck('code')->all());
        $response->assertJsonStructure([['id','code','name','is_default','branch'=>['id','name','is_active']]]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role'=>'admin']);
    }

    private function principalWarehouse(): Warehouse
    {
        return Warehouse::where('code',Warehouse::INITIAL_CODE)->firstOrFail();
    }

    private function payload(int $quantity, ?int $warehouseId): array
    {
        return array_filter([
            'name'=>'Producto desde formulario',
            'sku'=>'FORM-PRODUCT-'.uniqid(),
            'price'=>25.50,
            'cantidad_inicial'=>$quantity,
            'warehouse_id'=>$warehouseId,
        ],fn($value)=>$value!==null);
    }
}
