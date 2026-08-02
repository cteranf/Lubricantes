<?php

namespace Tests\Feature;

use App\Models\InventoryMovement;
use App\Models\InventoryReservation;
use App\Models\Order;
use App\Models\OrderHandlingHistory;
use App\Models\OrderHandlingIncident;
use App\Models\OrderHandlingItem;
use App\Models\OrderHandlingProcess;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Models\WarehouseInventory;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrderPickingPackingPhaseFourTest extends TestCase
{
    use RefreshDatabase;

    public function test_starting_preparation_initializes_once_and_preserves_item_snapshots(): void
    {
        [$admin,$product,$order]=$this->scenario(5,2); $item=$order->items()->firstOrFail();
        $this->startPreparation($admin,$order); $this->startPreparation($admin,$order);
        $this->assertSame(1,OrderHandlingProcess::where('order_id',$order->id)->count());
        $this->assertSame(1,OrderHandlingItem::where('order_item_id',$item->id)->count());
        $handling=$item->handlingItem()->firstOrFail();
        $this->assertSame($product->name,$handling->product_name); $this->assertSame($product->sku,$handling->product_sku);
        $this->assertSame($item->warehouse_id,$handling->warehouse_id); $this->assertSame(2,$handling->ordered_quantity);
    }

    public function test_picking_requires_preparing_and_records_starter_date_and_manual_method(): void
    {
        [$admin,,$order]=$this->scenario(); Sanctum::actingAs($admin);
        $this->postJson($this->handlingUrl($order,'picking/start'))->assertUnprocessable();
        $this->startPreparation($admin,$order);
        $response=$this->postJson($this->handlingUrl($order,'picking/start'),['observation'=>'Mesa A'])->assertOk();
        $response->assertJsonPath('picking.status','in_progress')->assertJsonPath('picking.started_by.id',$admin->id);
        $this->assertNotNull(OrderHandlingProcess::where('order_id',$order->id)->value('picking_started_at'));
        $this->assertDatabaseHas('order_handling_histories',['order_id'=>$order->id,'event_type'=>'picking_started','confirmation_method'=>'manual']);
    }

    public function test_picked_quantity_validates_bounds_records_manual_and_is_idempotent(): void
    {
        [$admin,,$order]=$this->scenario(5,2); $item=$order->items()->firstOrFail(); $this->startPicking($admin,$order);
        $url=$this->handlingUrl($order,"picking/items/{$item->id}");
        $this->patchJson($url,['picked_quantity'=>-1])->assertUnprocessable();
        $this->patchJson($url,['picked_quantity'=>3])->assertUnprocessable();
        $this->patchJson($url,['picked_quantity'=>1])->assertOk()->assertJsonPath('items.0.confirmation_method','manual');
        $this->patchJson($url,['picked_quantity'=>1])->assertOk();
        $this->assertSame(1,OrderHandlingHistory::where('order_id',$order->id)->where('event_type','picked_quantity_updated')->count());
    }

    public function test_picked_quantity_cannot_drop_below_packed_quantity(): void
    {
        [$admin,,$order]=$this->scenario(5,2); $this->completePicking($admin,$order); $this->startPacking($order);
        $item=$order->items()->firstOrFail(); $this->patchJson($this->handlingUrl($order,"packing/items/{$item->id}"),['packed_quantity'=>1])->assertOk();
        OrderHandlingProcess::where('order_id',$order->id)->update(['picking_status'=>'in_progress']);
        $this->patchJson($this->handlingUrl($order,"picking/items/{$item->id}"),['picked_quantity'=>0])->assertUnprocessable()->assertJsonValidationErrors('picked_quantity');
    }

    public function test_picking_completion_requires_all_items_and_is_idempotent(): void
    {
        [$admin,,$order]=$this->scenario(5,2); $this->startPicking($admin,$order); Sanctum::actingAs($admin);
        $this->postJson($this->handlingUrl($order,'picking/complete'))->assertUnprocessable();
        $item=$order->items()->firstOrFail(); $this->patchJson($this->handlingUrl($order,"picking/items/{$item->id}"),['picked_quantity'=>2])->assertOk();
        $this->postJson($this->handlingUrl($order,'picking/complete'))->assertOk()->assertJsonPath('picking.status','completed');
        $this->postJson($this->handlingUrl($order,'picking/complete'))->assertOk();
        $process=OrderHandlingProcess::where('order_id',$order->id)->firstOrFail();
        $this->assertSame($admin->id,$process->picking_completed_by); $this->assertNotNull($process->picking_completed_at);
        $this->assertSame(1,OrderHandlingHistory::where('order_id',$order->id)->where('event_type','picking_completed')->count());
    }

    public function test_packing_requires_completed_picking_and_records_starter(): void
    {
        [$admin,,$order]=$this->scenario(); $this->startPicking($admin,$order); Sanctum::actingAs($admin);
        $this->postJson($this->handlingUrl($order,'packing/start'))->assertUnprocessable();
        $this->fillPicked($order); $this->postJson($this->handlingUrl($order,'picking/complete'))->assertOk();
        $this->postJson($this->handlingUrl($order,'packing/start'))->assertOk()->assertJsonPath('packing.started_by.id',$admin->id);
        $this->assertNotNull(OrderHandlingProcess::where('order_id',$order->id)->value('packing_started_at'));
    }

    public function test_packed_quantity_validates_picked_and_ordered_bounds(): void
    {
        [$admin,,$order]=$this->scenario(5,2); $this->completePicking($admin,$order); $this->startPacking($order); $item=$order->items()->firstOrFail();
        $url=$this->handlingUrl($order,"packing/items/{$item->id}");
        $this->patchJson($url,['packed_quantity'=>-1])->assertUnprocessable();
        $this->patchJson($url,['packed_quantity'=>3])->assertUnprocessable()->assertJsonValidationErrors('packed_quantity');
        $this->patchJson($url,['packed_quantity'=>1])->assertOk()->assertJsonPath('items.0.confirmation_method','manual');
    }

    public function test_packing_completion_requires_all_items_and_is_idempotent(): void
    {
        [$admin,,$order]=$this->scenario(5,2); $this->completePicking($admin,$order); $this->startPacking($order); Sanctum::actingAs($admin);
        $this->postJson($this->handlingUrl($order,'packing/complete'))->assertUnprocessable();
        $this->fillPacked($order); $this->postJson($this->handlingUrl($order,'packing/complete'))->assertOk()->assertJsonPath('packing.status','completed');
        $this->postJson($this->handlingUrl($order,'packing/complete'))->assertOk();
        $this->assertSame(1,OrderHandlingHistory::where('order_id',$order->id)->where('event_type','packing_completed')->count());
    }

    public function test_ready_requires_complete_picking_and_packing_then_succeeds(): void
    {
        [$admin,,$order]=$this->scenario(); $this->startPreparation($admin,$order); Sanctum::actingAs($admin);
        $this->postJson($this->fulfillmentUrl($order,'ready'))->assertUnprocessable();
        $this->completePicking($admin,$order);
        $this->postJson($this->fulfillmentUrl($order,'ready'))->assertUnprocessable();
        $this->completePacking($order);
        $this->postJson($this->fulfillmentUrl($order,'ready'))->assertOk()->assertJsonPath('fulfillment.status','ready');
    }

    public function test_picking_and_packing_do_not_touch_inventory_movements_or_reservations(): void
    {
        [$admin,$product,$order]=$this->scenario(6,2); $this->startPreparation($admin,$order);
        $inventory=$this->inventory($product); $quantity=$inventory->quantity;
        $reservation=$order->reservations()->firstOrFail()->refresh(); $consumedAt=$reservation->consumed_at;
        $this->completePicking($admin,$order); $this->completePacking($order);
        $this->assertSame($quantity,$this->inventory($product)->quantity);
        $this->assertSame(1,InventoryMovement::where('type','sale')->where('reference_id',(string)$order->id)->count());
        $this->assertSame('consumed',$reservation->refresh()->status); $this->assertTrue($consumedAt->equalTo($reservation->consumed_at));
    }

    public function test_open_incident_blocks_completion_and_resolution_allows_continue(): void
    {
        [$admin,,$order]=$this->scenario(); $this->startPicking($admin,$order); $this->fillPicked($order); $item=$order->items()->firstOrFail(); Sanctum::actingAs($admin);
        $response=$this->postJson($this->handlingUrl($order,'incidents'),['order_item_id'=>$item->id,'type'=>'damaged','affected_quantity'=>1,'description'=>'Envase golpeado','idempotency_key'=>'test-incident-'.$order->id])->assertCreated();
        $incidentId=$response->json('incidents.0.id');
        $this->postJson($this->handlingUrl($order,'picking/complete'))->assertUnprocessable();
        $this->patchJson($this->handlingUrl($order,"incidents/{$incidentId}/resolve"),['observation'=>'Producto reemplazado'])->assertOk();
        $this->postJson($this->handlingUrl($order,'picking/complete'))->assertOk();
        $this->assertDatabaseHas('order_handling_incidents',['id'=>$incidentId,'status'=>'resolved','resolved_by'=>$admin->id]);
    }

    public function test_cancellation_preserves_handling_history_blocks_operations_and_does_not_return_twice(): void
    {
        [$admin,$product,$order]=$this->scenario(5,2); $this->startPicking($admin,$order); $item=$order->items()->firstOrFail(); Sanctum::actingAs($admin);
        $this->patchJson($this->handlingUrl($order,"picking/items/{$item->id}"),['picked_quantity'=>1])->assertOk();
        $this->postJson($this->fulfillmentUrl($order,'cancel'),['reason'=>'Cliente desistió'])->assertOk();
        $this->postJson($this->fulfillmentUrl($order,'cancel'),['reason'=>'Repetida'])->assertOk();
        $this->postJson($this->handlingUrl($order,'picking/start'))->assertUnprocessable();
        $this->assertSame(5,$this->inventory($product)->quantity);
        $this->assertSame(1,InventoryMovement::where('type','cancellation_return')->where('reference_id',(string)$order->id)->count());
        $this->assertSame(1,OrderHandlingHistory::where('order_id',$order->id)->where('event_type','operation_canceled')->count());
        $this->assertSame(1,OrderHandlingHistory::where('order_id',$order->id)->where('event_type','picked_quantity_updated')->count());
    }

    public function test_customer_is_forbidden_and_item_from_another_order_is_not_accepted(): void
    {
        [$admin,,$order]=$this->scenario(); $this->startPicking($admin,$order); [,,$other]=$this->scenario(); $foreignItem=$other->items()->firstOrFail();
        Sanctum::actingAs($this->customer());
        $this->getJson($this->handlingUrl($order,''))->assertForbidden();
        $this->patchJson($this->handlingUrl($order,"picking/items/{$order->items()->first()->id}"),['picked_quantity'=>1])->assertForbidden();
        Sanctum::actingAs($admin);
        $this->patchJson($this->handlingUrl($order,"picking/items/{$foreignItem->id}"),['picked_quantity'=>1])->assertNotFound();
    }

    public function test_legacy_ready_and_delivered_orders_are_readable_without_retroactive_inventory(): void
    {
        $admin=$this->admin(); $customer=$this->customer(); $before=InventoryMovement::count();
        foreach ([Order::FULFILLMENT_READY,Order::FULFILLMENT_DELIVERED] as $state) {
            $order=Order::create(['user_id'=>$customer->id,'status'=>$state==='ready'?'shipped':'delivered','total'=>10,'shipping_info'=>['address'=>'Legacy','city'=>'Lima'],'payment_method'=>'contra_entrega','payment_status'=>'pending','delivery_type'=>'delivery','tracking_status'=>$state==='ready'?'shipped':'delivered','fulfillment_status'=>$state]);
            Sanctum::actingAs($admin); $this->getJson($this->handlingUrl($order,''))->assertOk()->assertJsonPath('legacy',true);
        }
        $this->assertSame($before,InventoryMovement::count()); $this->assertSame(0,OrderHandlingProcess::count());
    }

    public function test_summary_exposes_iso_dates_progress_people_and_history(): void
    {
        [$admin,,$order]=$this->scenario(); $this->completePicking($admin,$order); Sanctum::actingAs($admin);
        $response=$this->getJson($this->handlingUrl($order,''))->assertOk()->assertJsonStructure(['picking'=>['status','progress','started_at','completed_at','started_by','completed_by'],'packing','totals','actions','items','incidents','history']);
        $this->assertMatchesRegularExpression('/(?:Z|[+-]\d{2}:\d{2})$/',$response->json('picking.started_at'));
        $this->assertSame(100,$response->json('picking.progress')); $this->assertNotEmpty($response->json('history'));
    }

    private function scenario(int $stock=5,int $quantity=1): array
    {
        $admin=$this->admin(); $product=$this->product($stock); Sanctum::actingAs($this->customer());
        $id=$this->postJson('/api/v1/orders',['shipping_info'=>['address'=>'Av. Fase 4','city'=>'Lima','phone'=>'999'],'payment_method'=>'contra_entrega','delivery_type'=>'delivery','items'=>[['product_id'=>$product->id,'quantity'=>$quantity]]])->assertCreated()->json('id');
        return [$admin,$product,Order::with(['items','reservations'])->findOrFail($id)];
    }
    private function startPreparation(User $admin,Order $order): void { Sanctum::actingAs($admin);$this->postJson($this->fulfillmentUrl($order,'start-preparation'))->assertOk(); }
    private function startPicking(User $admin,Order $order): void { $this->startPreparation($admin,$order);Sanctum::actingAs($admin);$this->postJson($this->handlingUrl($order,'picking/start'))->assertOk(); }
    private function fillPicked(Order $order): void { foreach($order->items as $item)$this->patchJson($this->handlingUrl($order,"picking/items/{$item->id}"),['picked_quantity'=>$item->quantity])->assertOk(); }
    private function completePicking(User $admin,Order $order): void { $this->startPicking($admin,$order);$this->fillPicked($order);$this->postJson($this->handlingUrl($order,'picking/complete'))->assertOk(); }
    private function startPacking(Order $order): void { $this->postJson($this->handlingUrl($order,'packing/start'))->assertOk(); }
    private function fillPacked(Order $order): void { foreach($order->items as $item)$this->patchJson($this->handlingUrl($order,"packing/items/{$item->id}"),['packed_quantity'=>$item->quantity])->assertOk(); }
    private function completePacking(Order $order): void { $this->startPacking($order);$this->fillPacked($order);$this->postJson($this->handlingUrl($order,'packing/complete'))->assertOk(); }
    private function handlingUrl(Order $order,string $path): string { return '/api/v1/admin/orders/'.$order->id.'/'.ltrim($path ?: 'picking-packing','/'); }
    private function fulfillmentUrl(Order $order,string $action): string { return '/api/v1/admin/orders/'.$order->id.'/fulfillment/'.$action; }
    private function product(int $stock): Product { $p=Product::create(['name'=>'Producto '.Str::random(6),'slug'=>'fase4-'.Str::uuid(),'sku'=>'P4-'.Str::random(8),'presentation'=>'1L','price'=>10,'is_active'=>true]);app(InventoryService::class)->initializeProduct($p,$stock,app(InventoryService::class)->defaultWarehouse());return $p->refresh(); }
    private function inventory(Product $product): WarehouseInventory { return WarehouseInventory::where('product_id',$product->id)->where('warehouse_id',app(InventoryService::class)->defaultWarehouse()->id)->firstOrFail()->refresh(); }
    private function admin(): User { return User::factory()->create(['role'=>'admin']); }
    private function customer(): User { return User::factory()->create(['role'=>'customer']); }
}
