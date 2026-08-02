<?php

namespace Tests\Feature;

use App\Models\InventoryMovement;
use App\Models\InventoryReservation;
use App\Models\Order;
use App\Models\OrderFulfillmentHistory;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Models\WarehouseInventory;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrderFulfillmentPhaseThreeBTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_cash_on_delivery_order_stays_reserved_without_physical_sale(): void
    {
        $product=$this->product(5); $order=$this->createOrder($product,2,'contra_entrega'); $item=$order->items()->firstOrFail();
        $this->assertSame('reserved',$order->fulfillment_status); $this->assertSame('active',$item->reservation->status);
        $this->assertSame(5,$this->inventory($product)->quantity); $this->assertSame(2,$this->inventory($product)->reserved_quantity);
        $this->assertDatabaseMissing('inventory_movements',['idempotency_key'=>'sale-order-item-'.$item->id]);
    }

    public function test_starting_cash_on_delivery_preparation_consumes_once_and_records_audit(): void
    {
        $admin=$this->admin(); $product=$this->product(5); $order=$this->createOrder($product,2,'contra_entrega'); $item=$order->items()->firstOrFail(); Sanctum::actingAs($admin);
        $this->postJson($this->url($order,'start-preparation'),['observation'=>'Mesa 1'])->assertOk()->assertJsonPath('fulfillment.status','preparing');
        $this->postJson($this->url($order,'start-preparation'))->assertOk();
        $order->refresh(); $inventory=$this->inventory($product);
        $this->assertSame(3,$inventory->quantity); $this->assertSame(0,$inventory->reserved_quantity); $this->assertSame(3,$product->refresh()->stock);
        $this->assertSame('pending',$order->payment_status); $this->assertNotNull($order->preparing_at); $this->assertSame($admin->id,$order->prepared_by);
        $this->assertSame(1,InventoryMovement::where('idempotency_key','sale-order-item-'.$item->id)->count());
        $this->assertSame(1,OrderFulfillmentHistory::where(['order_id'=>$order->id,'to_status'=>'preparing'])->count());
    }

    public function test_online_approved_and_transfer_approved_do_not_discount_again_when_preparing(): void
    {
        config()->set('payment.default_gateway','mock'); config()->set('payment.mock.enabled',true); $admin=$this->admin();
        $onlineProduct=$this->product(5); $online=$this->createOrder($onlineProduct,2,'card');
        $this->postJson('/api/v1/payment/webhook',['payment_id'=>'FUL-ONLINE','order_id'=>$online->id])->assertOk(); $before=$this->inventory($onlineProduct)->quantity;
        Sanctum::actingAs($admin); $this->postJson($this->url($online,'start-preparation'))->assertOk();
        $this->assertSame($before,$this->inventory($onlineProduct)->quantity);
        $this->assertSame(1,InventoryMovement::where('reference_id',(string)$online->id)->where('type','sale')->count());

        $transferProduct=$this->product(5); $transfer=$this->createOrder($transferProduct,2,'transferencia'); Sanctum::actingAs($admin);
        $this->postJson($this->url($transfer,'approve-transfer'))->assertOk()->assertJsonPath('fulfillment.payment_status','approved'); $before=$this->inventory($transferProduct)->quantity;
        $this->postJson($this->url($transfer,'start-preparation'))->assertOk();
        $this->assertSame($before,$this->inventory($transferProduct)->quantity);
        $this->assertSame(1,InventoryMovement::where('reference_id',(string)$transfer->id)->where('type','sale')->count());
    }

    public function test_ready_and_delivered_require_order_and_do_not_change_inventory_and_cod_becomes_paid(): void
    {
        $admin=$this->admin(); $product=$this->product(4); $order=$this->createOrder($product,1,'contra_entrega'); Sanctum::actingAs($admin);
        $this->postJson($this->url($order,'ready'))->assertUnprocessable();
        $this->postJson($this->url($order,'delivered'))->assertUnprocessable();
        $this->postJson($this->url($order,'start-preparation'))->assertOk(); $physical=$this->inventory($product)->quantity;
        $this->completeHandling($order);
        $this->postJson($this->url($order,'ready'),['observation'=>'Empacado'])->assertOk()->assertJsonPath('fulfillment.status','ready');
        $this->assertSame($physical,$this->inventory($product)->quantity);
        $this->postJson($this->url($order,'delivered'),['observation'=>'Recibido'])->assertUnprocessable();
        $this->completeDelivery($order);
        $this->postJson('/api/v1/admin/orders/'.$order->id.'/delivery/confirm',$this->deliveryPayload())->assertOk();
        $order->refresh(); $this->assertSame($physical,$this->inventory($product)->quantity); $this->assertSame('approved',$order->payment_status); $this->assertNotNull($order->paid_at);
        $this->assertTrue($order->paid_at->lessThanOrEqualTo($order->delivered_at));
        $this->assertNotNull($order->ready_at); $this->assertNotNull($order->delivered_at); $this->assertSame($admin->id,$order->ready_by); $this->assertSame($admin->id,$order->delivered_by);
        $this->assertSame(3,OrderFulfillmentHistory::where('order_id',$order->id)->count());
    }

    public function test_customer_tracking_uses_public_image_url_iso_instants_and_clear_cod_payment_event(): void
    {
        $admin=$this->admin(); $product=$this->product(4); $product->update(['image_path'=>'/storage/products/aceite.jpg']);
        $order=$this->createOrder($product,1,'contra_entrega'); Sanctum::actingAs($admin);
        $this->postJson($this->url($order,'start-preparation'))->assertOk();
        $this->completeHandling($order);
        $this->postJson($this->url($order,'ready'))->assertOk();
        $this->completeDelivery($order);

        $order->refresh(); Sanctum::actingAs($order->user);
        $response=$this->getJson('/api/v1/orders/'.$order->id.'/tracking')->assertOk();
        $timeline=$response->json('timeline');
        $delivered=collect($timeline)->firstWhere('status','delivered');
        $payment=collect($timeline)->last();

        $response->assertJsonPath('order.items.0.image_url',config('filesystems.disks.public.url').'/products/aceite.jpg');
        $this->assertMatchesRegularExpression('/(?:Z|[+-]00:00)$/',$response->json('order.created_at'));
        $this->assertSame('payment',$payment['status']);
        $this->assertSame('Pago confirmado',$payment['label']);
        $this->assertTrue($payment['completed']);
        $this->assertSame($order->paid_at->copy()->utc()->toIso8601String(),$payment['date']);
    }

    public function test_product_image_url_does_not_expose_local_paths_and_tracking_allows_no_image(): void
    {
        $product=$this->product(2); $product->update(['image_path'=>'C:\\private\\products\\secret.jpg']);
        $this->assertNull($product->refresh()->image_url);

        $product->update(['image_path'=>null]); $order=$this->createOrder($product,1,'contra_entrega'); Sanctum::actingAs($order->user);
        $this->getJson('/api/v1/orders/'.$order->id.'/tracking')->assertOk()->assertJsonPath('order.items.0.image_url',null);
    }

    public function test_cancellation_before_preparation_releases_without_return_and_cannot_prepare_afterward(): void
    {
        $admin=$this->admin(); $product=$this->product(5); $order=$this->createOrder($product,2,'contra_entrega'); Sanctum::actingAs($admin);
        $this->postJson($this->url($order,'cancel'),['reason'=>'Cliente desistio'])->assertOk()->assertJsonPath('fulfillment.status','canceled');
        $this->assertSame(5,$this->inventory($product)->quantity); $this->assertSame(0,$this->inventory($product)->reserved_quantity);
        $this->assertSame(0,InventoryMovement::where('type','cancellation_return')->where('reference_id',(string)$order->id)->count());
        $this->postJson($this->url($order,'start-preparation'))->assertUnprocessable();
    }

    public function test_cancellation_after_preparation_restores_once(): void
    {
        $admin=$this->admin(); $product=$this->product(5); $order=$this->createOrder($product,2,'contra_entrega'); $item=$order->items()->firstOrFail(); Sanctum::actingAs($admin);
        $this->postJson($this->url($order,'start-preparation'))->assertOk(); $this->assertSame(3,$this->inventory($product)->quantity);
        $this->postJson($this->url($order,'cancel'),['reason'=>'Incidencia operativa'])->assertOk();
        $this->postJson($this->url($order,'cancel'),['reason'=>'Repetida'])->assertOk();
        $this->assertSame(5,$this->inventory($product)->quantity);
        $this->assertSame(1,InventoryMovement::where('idempotency_key','cancellation-order-item-'.$item->id)->count());
        $this->assertSame(1,OrderFulfillmentHistory::where(['order_id'=>$order->id,'to_status'=>'canceled'])->count());
    }

    public function test_consumed_reservation_and_preparing_order_are_not_expired(): void
    {
        $admin=$this->admin(); $product=$this->product(3); $order=$this->createOrder($product,1,'contra_entrega'); Sanctum::actingAs($admin);
        $this->postJson($this->url($order,'start-preparation'))->assertOk(); InventoryReservation::where('order_id',$order->id)->update(['expires_at'=>now()->subMinute()]);
        $before=$this->inventory($product)->quantity; $this->artisan('inventory:expire-reservations')->assertSuccessful();
        $this->assertSame($before,$this->inventory($product)->quantity); $this->assertSame('preparing',$order->refresh()->fulfillment_status); $this->assertDatabaseHas('inventory_reservations',['order_id'=>$order->id,'status'=>'consumed']);
    }

    public function test_customer_is_forbidden_and_admin_response_contains_actions_people_and_history(): void
    {
        $product=$this->product(2); $order=$this->createOrder($product,1,'contra_entrega'); Sanctum::actingAs($this->customer());
        $this->postJson($this->url($order,'start-preparation'))->assertForbidden(); $this->getJson('/api/v1/admin/orders/'.$order->id.'/fulfillment')->assertForbidden();
        Sanctum::actingAs($this->admin()); $this->getJson('/api/v1/admin/orders/'.$order->id.'/fulfillment')->assertOk()->assertJsonStructure(['order'=>['items'],'fulfillment'=>['status','label','actions','payment_status','reservation_status','warehouse','expires_at','history']]);
    }

    public function test_legacy_order_preparation_never_duplicates_existing_sale(): void
    {
        $admin=$this->admin(); $customer=$this->customer(); $product=$this->product(5);
        $order=Order::create(['user_id'=>$customer->id,'status'=>'confirmed','total'=>20,'shipping_info'=>['address'=>'Legacy','city'=>'Lima'],'payment_method'=>'contra_entrega','payment_status'=>'pending','delivery_type'=>'delivery','tracking_status'=>'confirmed']);
        $item=OrderItem::create(['order_id'=>$order->id,'product_id'=>$product->id,'warehouse_id'=>app(InventoryService::class)->defaultWarehouse()->id,'quantity'=>2,'price'=>10,'subtotal'=>20]); $item->setRelation('product',$product);$item->setRelation('warehouse',app(InventoryService::class)->defaultWarehouse());app(InventoryService::class)->sell($item,$customer);
        $before=$this->inventory($product)->quantity; Sanctum::actingAs($admin); $this->postJson($this->url($order,'start-preparation'))->assertOk();
        $this->assertSame($before,$this->inventory($product)->quantity); $this->assertSame(1,InventoryMovement::where('idempotency_key','sale-order-item-'.$item->id)->count());
    }

    private function createOrder(Product $product,int $quantity,string $method): Order { Sanctum::actingAs($this->customer());$id=$this->postJson('/api/v1/orders',['shipping_info'=>['address'=>'Av. Operativa','city'=>'Lima','phone'=>'999'],'payment_method'=>$method,'delivery_type'=>'delivery','items'=>[['product_id'=>$product->id,'quantity'=>$quantity]]])->assertCreated()->json('id');return Order::with('items.reservation')->findOrFail($id); }
    private function product(int $stock): Product { $p=Product::create(['name'=>'Producto '.Str::random(6),'slug'=>'producto-'.Str::uuid(),'sku'=>'FUL-'.Str::random(8),'price'=>10,'is_active'=>true]);app(InventoryService::class)->initializeProduct($p,$stock,app(InventoryService::class)->defaultWarehouse());return $p->refresh(); }
    private function inventory(Product $product): WarehouseInventory { return WarehouseInventory::where('product_id',$product->id)->where('warehouse_id',app(InventoryService::class)->defaultWarehouse()->id)->firstOrFail()->refresh(); }
    private function url(Order $order,string $action): string { return '/api/v1/admin/orders/'.$order->id.'/fulfillment/'.$action; }
    private function completeHandling(Order $order): void { $base='/api/v1/admin/orders/'.$order->id;$this->postJson($base.'/picking/start')->assertOk();foreach($order->items as $item)$this->patchJson($base.'/picking/items/'.$item->id,['picked_quantity'=>$item->quantity])->assertOk();$this->postJson($base.'/picking/complete')->assertOk();$this->postJson($base.'/packing/start')->assertOk();foreach($order->items as $item)$this->patchJson($base.'/packing/items/'.$item->id,['packed_quantity'=>$item->quantity])->assertOk();$this->postJson($base.'/packing/complete')->assertOk(); }
    private function completeDelivery(Order $order): void { $base='/api/v1/admin/orders/'.$order->id.'/delivery';$driver=User::factory()->create(['role'=>'admin','can_deliver'=>true]);$this->postJson($base.'/initialize')->assertCreated();$this->postJson($base.'/assign-driver',['delivery_user_id'=>$driver->id])->assertOk();$this->postJson($base.'/dispatch')->assertOk();$this->postJson($base.'/attempts')->assertCreated();$this->postJson($base.'/out-for-delivery')->assertOk();$this->postJson($base.'/confirm',$this->deliveryPayload())->assertOk()->assertJsonPath('delivery.status','delivered'); }
    private function deliveryPayload(): array { return ['recipient_name'=>'Cliente de prueba','recipient_document_type'=>'DNI','recipient_document_number'=>'12345678','confirmation_method'=>'manual','money_received'=>true,'collection_method'=>'cash']; }
    private function admin(): User { return User::factory()->create(['role'=>'admin']); }
    private function customer(): User { return User::factory()->create(['role'=>'customer']); }
}
