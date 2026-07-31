<?php

namespace Tests\Feature;

use App\Exceptions\InventoryException;
use App\Models\InventoryMovement;
use App\Models\InventoryReservation;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseInventory;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InventoryReservationPhaseThreeTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_order_reserves_available_stock_without_changing_physical_or_products_stock(): void
    {
        $product=$this->product(10); $order=$this->createOrder($product,4); $item=$order->items()->firstOrFail();
        $inventory=$this->inventory($product);
        $this->assertSame(10,$inventory->quantity); $this->assertSame(4,$inventory->reserved_quantity); $this->assertSame(6,$inventory->available_quantity);
        $this->assertSame(10,$product->refresh()->stock);
        $this->assertDatabaseHas('inventory_reservations',['order_id'=>$order->id,'order_item_id'=>$item->id,'product_id'=>$product->id,'warehouse_id'=>$inventory->warehouse_id,'quantity'=>4,'status'=>'active','idempotency_key'=>'reservation-order-item-'.$item->id]);
        $this->assertDatabaseMissing('inventory_movements',['idempotency_key'=>'sale-order-item-'.$item->id]);
    }

    public function test_catalog_cart_and_checkout_use_physical_minus_reserved_from_principal(): void
    {
        $product=$this->product(10); $this->createOrder($product,4);
        $this->getJson('/api/v1/products/'.$product->slug)->assertOk()->assertJsonPath('stock',6);
        Sanctum::actingAs($this->customer());
        $this->postJson('/api/v1/cart',['items'=>[['product_id'=>$product->id,'quantity'=>6]]])->assertOk()->assertJsonPath('valid',true)->assertJsonPath('items.0.available_quantity',6);
        $this->postJson('/api/v1/orders',$this->payload($product,7))->assertUnprocessable();
        $this->assertSame(10,$this->inventory($product)->quantity);
    }

    public function test_competing_orders_cannot_reserve_the_same_last_units(): void
    {
        $product=$this->product(1); $this->createOrder($product,1);
        Sanctum::actingAs($this->customer());
        $this->postJson('/api/v1/orders',$this->payload($product,1))->assertUnprocessable();
        $this->assertSame(1,InventoryReservation::where('product_id',$product->id)->where('status','active')->count());
        $this->assertSame(1,$this->inventory($product)->reserved_quantity);
    }

    public function test_approved_payment_consumes_once_and_duplicate_callback_is_idempotent(): void
    {
        config()->set('payment.default_gateway','mock'); config()->set('payment.mock.enabled',true);
        $product=$this->product(5); $order=$this->createOrder($product,2); $item=$order->items()->firstOrFail();
        $payload=['payment_id'=>'MOCK-RESERVATION','order_id'=>$order->id];
        $this->postJson('/api/v1/payment/webhook',$payload)->assertOk();
        $this->postJson('/api/v1/payment/webhook',$payload)->assertOk();
        $inventory=$this->inventory($product); $order->refresh();
        $this->assertSame(3,$inventory->quantity); $this->assertSame(0,$inventory->reserved_quantity); $this->assertSame(3,$product->refresh()->stock);
        $this->assertNotNull($order->paid_at); $this->assertSame('approved',$order->payment_status);
        $this->assertDatabaseHas('inventory_reservations',['order_item_id'=>$item->id,'status'=>'consumed']);
        $this->assertSame(1,InventoryMovement::where('idempotency_key','sale-order-item-'.$item->id)->count());
    }

    public function test_unpaid_cancellation_releases_without_return_but_paid_cancellation_restores_once(): void
    {
        $admin=$this->admin();
        $unpaidProduct=$this->product(5); $unpaid=$this->createOrder($unpaidProduct,2);
        Sanctum::actingAs($admin); $this->putJson('/api/v1/admin/orders/'.$unpaid->id,['status'=>'canceled'])->assertOk();
        $this->putJson('/api/v1/admin/orders/'.$unpaid->id,['status'=>'canceled'])->assertOk();
        $this->assertSame(0,$this->inventory($unpaidProduct)->reserved_quantity); $this->assertSame(5,$this->inventory($unpaidProduct)->quantity);
        $this->assertSame(0,InventoryMovement::where('type',InventoryMovement::CANCELLATION_RETURN)->where('reference_id',(string)$unpaid->id)->count());

        config()->set('payment.default_gateway','mock'); config()->set('payment.mock.enabled',true);
        $paidProduct=$this->product(5); $paid=$this->createOrder($paidProduct,2); $item=$paid->items()->firstOrFail();
        $this->postJson('/api/v1/payment/webhook',['payment_id'=>'MOCK-PAID-CANCEL','order_id'=>$paid->id])->assertOk();
        Sanctum::actingAs($admin); $this->putJson('/api/v1/admin/orders/'.$paid->id,['status'=>'canceled'])->assertOk();
        $this->putJson('/api/v1/admin/orders/'.$paid->id,['status'=>'canceled'])->assertOk();
        $this->assertSame(5,$this->inventory($paidProduct)->quantity);
        $this->assertSame(1,InventoryMovement::where('idempotency_key','cancellation-order-item-'.$item->id)->count());
    }

    public function test_expiration_command_releases_once_and_cancels_pending_order(): void
    {
        $product=$this->product(3); $order=$this->createOrder($product,2);
        InventoryReservation::where('order_id',$order->id)->update(['expires_at'=>now()->subMinute()]);
        $this->artisan('inventory:expire-reservations')->expectsOutputToContain('procesadas=1')->assertSuccessful();
        $this->artisan('inventory:expire-reservations')->expectsOutputToContain('procesadas=0')->assertSuccessful();
        $this->assertDatabaseHas('inventory_reservations',['order_id'=>$order->id,'status'=>'expired']);
        $this->assertSame(0,$this->inventory($product)->reserved_quantity); $this->assertSame(3,$this->inventory($product)->quantity);
        $this->assertSame('canceled',$order->refresh()->status);
    }

    public function test_manual_out_correction_and_transfer_cannot_use_reserved_units(): void
    {
        $product=$this->product(5); $this->createOrder($product,4); $warehouse=$this->warehouse(); $service=app(InventoryService::class);
        foreach ([
            fn()=> $service->manualOut($product,$warehouse,2,'Salida bloqueada'),
            fn()=> $service->adjust($product,$warehouse,3,'Conteo bloqueado'),
        ] as $operation) { try{$operation();$this->fail('La operacion debio respetar reservas.');}catch(InventoryException){} }
        $destination=Warehouse::create(['branch_id'=>$warehouse->branch_id,'code'=>'RES-DEST','name'=>'Destino','is_default'=>false,'is_active'=>true]);
        try{$service->transfer($product,$warehouse,$destination,2,'Traslado bloqueado');$this->fail('El traslado debio respetar reservas.');}catch(InventoryException){}
        $this->assertSame(5,$this->inventory($product)->quantity); $this->assertSame(4,$this->inventory($product)->reserved_quantity);
    }

    public function test_products_stock_remains_physical_total_and_initial_creation_still_works(): void
    {
        $product=$this->product(7); $this->createOrder($product,3);
        $this->assertSame(7,$product->refresh()->stock); $this->assertSame(7,(int)WarehouseInventory::where('product_id',$product->id)->sum('quantity'));
        Sanctum::actingAs($this->admin());
        $response=$this->postJson('/api/v1/admin/products',['name'=>'Producto fase 3','sku'=>'PHASE-3','price'=>20,'cantidad_inicial'=>4,'warehouse_id'=>$this->warehouse()->id])->assertCreated();
        $this->assertDatabaseHas('warehouse_inventories',['product_id'=>$response->json('id'),'quantity'=>4,'reserved_quantity'=>0]);
    }

    public function test_legacy_pending_order_is_not_reserved_or_discounted_a_second_time(): void
    {
        config()->set('payment.default_gateway','mock'); config()->set('payment.mock.enabled',true);
        $customer=$this->customer(); $product=$this->product(5);
        $order=Order::create(['user_id'=>$customer->id,'status'=>'pending','total'=>20,'shipping_info'=>['address'=>'Legacy','city'=>'Lima'],'payment_method'=>'card','payment_status'=>'pending','delivery_type'=>'delivery','tracking_status'=>'pending']);
        $item=OrderItem::create(['order_id'=>$order->id,'product_id'=>$product->id,'warehouse_id'=>$this->warehouse()->id,'quantity'=>2,'price'=>10,'subtotal'=>20]);
        $item->setRelation('product',$product); $item->setRelation('warehouse',$this->warehouse()); app(InventoryService::class)->sell($item,$customer);
        $this->assertSame(3,$this->inventory($product)->quantity);
        $this->postJson('/api/v1/payment/webhook',['payment_id'=>'MOCK-LEGACY','order_id'=>$order->id])->assertOk();
        $this->assertSame(3,$this->inventory($product)->quantity);
        $this->assertSame(1,InventoryMovement::where('idempotency_key','sale-order-item-'.$item->id)->count());
        $this->assertDatabaseCount('inventory_reservations',0);
    }

    private function createOrder(Product $product,int $quantity): Order
    {
        Sanctum::actingAs($this->customer());
        $id=$this->postJson('/api/v1/orders',$this->payload($product,$quantity))->assertCreated()->json('id');
        return Order::findOrFail($id);
    }
    private function payload(Product $product,int $quantity): array { return ['shipping_info'=>['address'=>'Av. Reserva 123','city'=>'Lima','phone'=>'999999999'],'payment_method'=>'card','delivery_type'=>'delivery','items'=>[['product_id'=>$product->id,'quantity'=>$quantity]]]; }
    private function product(int $stock): Product { $product=Product::create(['name'=>'Producto '.Str::random(6),'slug'=>'producto-'.Str::uuid(),'sku'=>'RES-'.Str::random(8),'price'=>10,'is_active'=>true]); app(InventoryService::class)->initializeProduct($product,$stock,$this->warehouse()); return $product->refresh(); }
    private function inventory(Product $product): WarehouseInventory { return WarehouseInventory::where('warehouse_id',$this->warehouse()->id)->where('product_id',$product->id)->firstOrFail()->refresh(); }
    private function warehouse(): Warehouse { return app(InventoryService::class)->defaultWarehouse(); }
    private function customer(): User { return User::factory()->create(['role'=>'customer']); }
    private function admin(): User { return User::factory()->create(['role'=>'admin']); }
}
