<?php

namespace Tests\Feature;

use App\Events\OrderDeliveryEvent;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\OrderDelivery;
use App\Models\OrderDeliveryAttempt;
use App\Models\OrderDeliveryHistory;
use App\Models\PaymentTransaction;
use App\Models\User;
use App\Services\OrderPaymentService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrderDeliveryPhaseFiveTest extends TestCase
{
    use RefreshDatabase;

    public function test_cash_collection_is_cod_only_uses_order_total_and_is_idempotent(): void
    {
        $admin=$this->admin();$order=$this->readyOrder('contra_entrega');$service=app(OrderPaymentService::class);
        $transaction=$service->confirmCashOnDeliveryCollection($order,$admin,'cash','REC-1');
        $same=$service->confirmCashOnDeliveryCollection($order,$admin,'cash','REC-1');
        $this->assertSame($transaction->id,$same->id);$this->assertSame('37.50',$transaction->amount);$this->assertSame('PEN',$transaction->currency);
        $this->assertSame($admin->id,$transaction->collected_by);$this->assertNotNull($transaction->collected_at);$this->assertSame(1,PaymentTransaction::count());
        $order->refresh();$this->assertSame('approved',$order->payment_status);$this->assertNotNull($order->paid_at);
        $this->expectException(\Illuminate\Validation\ValidationException::class);$service->confirmCashOnDeliveryCollection($this->readyOrder('card'),$admin);
    }

    public function test_database_keys_prevent_duplicate_approved_cod_and_reuse_between_orders(): void
    {
        $admin=$this->admin();$first=$this->readyOrder();$second=$this->readyOrder();app(OrderPaymentService::class)->confirmCashOnDeliveryCollection($first,$admin);
        $this->expectException(QueryException::class);
        PaymentTransaction::create(['order_id'=>$second->id,'payment_method'=>'contra_entrega','transaction_type'=>'payment','status'=>'approved','amount'=>$second->total,'currency'=>'PEN','idempotency_key'=>'cod-collection-order-'.$first->id,'approved_scope_key'=>'cod-approved-order-'.$second->id]);
    }

    public function test_delivery_requires_ready_and_initialization_is_idempotent_with_address_snapshot(): void
    {
        $admin=$this->admin();$notReady=$this->readyOrder();$notReady->update(['fulfillment_status'=>'preparing']);Sanctum::actingAs($admin);
        $this->postJson($this->url($notReady,'initialize'))->assertUnprocessable();
        $order=$this->readyOrder();$this->postJson($this->url($order,'initialize'))->assertCreated();$this->postJson($this->url($order,'initialize'))->assertCreated();
        $this->assertSame(1,OrderDelivery::where('order_id',$order->id)->count());$delivery=$order->delivery()->firstOrFail();$this->assertSame('Av. Histórica 123',$delivery->destination_address);
        $order->user->update(['addresses'=>[['address'=>'Dirección cambiada']]]);$this->assertSame('Av. Histórica 123',$delivery->refresh()->destination_address);
    }

    public function test_customer_is_forbidden_and_invalid_method_is_rejected(): void
    {
        $order=$this->readyOrder();Sanctum::actingAs($order->user);$this->postJson($this->url($order,'initialize'))->assertForbidden();
        Sanctum::actingAs($this->admin());$this->postJson($this->url($order,'initialize'))->assertCreated();$this->patchJson($this->url($order,'method'),['method'=>'drone'])->assertUnprocessable();
    }

    public function test_store_pickup_requires_active_warehouse_and_receiver_data(): void
    {
        $admin=$this->admin();$order=$this->readyOrder();Sanctum::actingAs($admin);$this->initialize($order);$this->patchJson($this->url($order,'method'),['method'=>'store_pickup'])->assertOk();
        $warehouse=\App\Models\Warehouse::firstOrFail();$warehouse->update(['is_active'=>false]);$this->postJson($this->url($order,'schedule-pickup'),['warehouse_id'=>$warehouse->id])->assertUnprocessable();$warehouse->update(['is_active'=>true]);
        $this->postJson($this->url($order,'schedule-pickup'),['warehouse_id'=>$warehouse->id])->assertOk()->assertJsonPath('delivery.status','awaiting_pickup');
        $this->postJson($this->url($order,'confirm'),['confirmation_method'=>'manual'])->assertUnprocessable();
    }

    public function test_own_delivery_requires_explicit_driver_assignment_and_valid_sequence(): void
    {
        $admin=$this->admin();$driver=User::factory()->create(['role'=>'admin','can_deliver'=>false]);$order=$this->readyOrder();Sanctum::actingAs($admin);$this->initialize($order);
        $this->postJson($this->url($order,'dispatch'))->assertUnprocessable();$this->postJson($this->url($order,'assign-driver'),['delivery_user_id'=>$driver->id])->assertUnprocessable();
        $driver->update(['can_deliver'=>true]);$this->postJson($this->url($order,'assign-driver'),['delivery_user_id'=>$driver->id,'vehicle_plate'=>'ABC-123'])->assertOk()->assertJsonPath('delivery.delivery_user_id',$driver->id);
        $delivery=$order->delivery()->firstOrFail();$this->assertSame($admin->id,$delivery->assigned_by);$this->assertNotNull($delivery->assigned_at);
        $this->postJson($this->url($order,'out-for-delivery'))->assertUnprocessable();$this->postJson($this->url($order,'dispatch'))->assertOk();$this->postJson($this->url($order,'out-for-delivery'))->assertUnprocessable();
        $this->postJson($this->url($order,'attempts'))->assertCreated();$this->postJson($this->url($order,'out-for-delivery'))->assertOk()->assertJsonPath('delivery.status','out_for_delivery');
    }

    public function test_external_courier_is_manual_and_requires_guide_before_dispatch(): void
    {
        $order=$this->readyOrder();Sanctum::actingAs($this->admin());$this->initialize($order);$this->patchJson($this->url($order,'method'),['method'=>'external_courier'])->assertOk();
        $this->postJson($this->url($order,'assign-courier'),[])->assertUnprocessable();$this->postJson($this->url($order,'assign-courier'),['courier_name'=>'Olva Manual'])->assertOk();$this->postJson($this->url($order,'dispatch'))->assertUnprocessable();
        $this->patchJson($this->url($order,'courier-tracking'),['tracking_number'=>'GUIA-1','tracking_url'=>'https://example.test/track/GUIA-1'])->assertOk();$this->postJson($this->url($order,'dispatch'))->assertOk();
        $this->assertSame('GUIA-1',$order->delivery()->value('tracking_number'));
    }

    public function test_failed_attempt_requires_reason_and_description_does_not_touch_inventory_or_payment(): void
    {
        [$order,$attempt]=$this->orderOutForDelivery();$beforeMovements=InventoryMovement::count();$beforePayment=PaymentTransaction::count();
        $this->patchJson($this->url($order,'attempts/'.$attempt->id.'/fail'),['failure_reason'=>'recipient_absent'])->assertUnprocessable();
        $this->patchJson($this->url($order,'attempts/'.$attempt->id.'/fail'),['failure_reason'=>'recipient_absent','failure_description'=>'No respondió nadie'])->assertOk()->assertJsonPath('delivery.status','failed_attempt');
        $this->assertSame($beforeMovements,InventoryMovement::count());$this->assertSame($beforePayment,PaymentTransaction::count());$this->assertSame('pending',$order->refresh()->payment_status);
    }

    public function test_attempt_from_another_order_cannot_be_operated_and_reschedule_preserves_previous(): void
    {
        [$first,$attempt]=$this->orderOutForDelivery();[$second]=$this->orderOutForDelivery();
        $this->patchJson($this->url($second,'attempts/'.$attempt->id.'/fail'),['failure_reason'=>'other','failure_description'=>'Cruce'])->assertNotFound();
        $this->patchJson($this->url($first,'attempts/'.$attempt->id.'/fail'),['failure_reason'=>'other','failure_description'=>'Falla real'])->assertOk();
        $this->postJson($this->url($first,'reschedule'),['scheduled_at'=>now()->addDay()->toIso8601String()])->assertCreated();
        $this->assertDatabaseHas('order_delivery_attempts',['id'=>$attempt->id,'status'=>'failed']);$this->assertDatabaseHas('order_delivery_attempts',['order_delivery_id'=>$first->delivery->id,'attempt_number'=>2,'status'=>'scheduled']);
    }

    public function test_cod_delivery_requires_money_and_creates_one_payment_and_one_delivery_without_inventory(): void
    {
        Event::fake();[$order]=$this->orderOutForDelivery();$movements=InventoryMovement::count();
        $payload=$this->recipient();$this->postJson($this->url($order,'confirm'),$payload)->assertUnprocessable();
        $payload['money_received']=true;$payload['collection_method']='cash';$payload['amount']='0.01';$payload['currency']='USD';
        $this->postJson($this->url($order,'confirm'),$payload)->assertOk()->assertJsonPath('delivery.status','delivered')->assertJsonPath('payment_transaction.currency','PEN')->assertJsonPath('payment_transaction.amount','37.50');
        $this->postJson($this->url($order,'confirm'),$payload)->assertOk();
        $this->assertSame(1,PaymentTransaction::where('order_id',$order->id)->count());$this->assertSame($movements,InventoryMovement::count());$this->assertSame('delivered',$order->refresh()->fulfillment_status);$this->assertSame(1,OrderDeliveryHistory::where(['order_id'=>$order->id,'event_type'=>'delivery_completed'])->count());Event::assertDispatched(OrderDeliveryEvent::class);
    }

    public function test_evidence_is_private_validated_and_securely_named(): void
    {
        Storage::fake('local');[$order]=$this->orderOutForDelivery();$payload=$this->recipient()+['money_received'=>true,'delivery_photo'=>UploadedFile::fake()->image('original.jpg')];
        $response=$this->post($this->url($order,'confirm'),$payload,['Accept'=>'application/json'])->assertOk();$path=$order->delivery()->value('delivery_photo_path');Storage::disk('local')->assertExists($path);$this->assertStringNotContainsString('original.jpg',$path);
        $customer=$order->user;Sanctum::actingAs($customer);$this->getJson('/api/v1/admin/orders/'.$order->id.'/delivery/evidence/photo')->assertForbidden();
        [$invalid]=$this->orderOutForDelivery();$this->post($this->url($invalid,'confirm'),$this->recipient()+['money_received'=>true,'delivery_photo'=>UploadedFile::fake()->create('evil.svg',10,'image/svg+xml')],['Accept'=>'application/json'])->assertUnprocessable();
    }

    public function test_cancel_is_allowed_before_dispatch_and_blocked_after_dispatch(): void
    {
        $admin=$this->admin();$before=$this->readyOrder();Sanctum::actingAs($admin);$this->initialize($before);$this->postJson($this->url($before,'cancel'),['reason'=>'Cliente desistió'])->assertOk()->assertJsonPath('delivery.status','canceled');
        $after=$this->readyOrder();$driver=User::factory()->create(['role'=>'admin','can_deliver'=>true]);$this->initialize($after);$this->postJson($this->url($after,'assign-driver'),['delivery_user_id'=>$driver->id])->assertOk();$this->postJson($this->url($after,'dispatch'))->assertOk();$this->postJson($this->url($after,'cancel'),['reason'=>'Ya salió'])->assertUnprocessable();$this->postJson('/api/v1/admin/orders/'.$after->id.'/fulfillment/cancel',['reason'=>'Ya salió'])->assertUnprocessable();
    }

    public function test_customer_tracking_exposes_safe_courier_data_not_internal_or_evidence(): void
    {
        $order=$this->readyOrder('card');Sanctum::actingAs($this->admin());$this->initialize($order);$this->patchJson($this->url($order,'method'),['method'=>'external_courier'])->assertOk();$this->postJson($this->url($order,'assign-courier'),['courier_name'=>'Courier Seguro','tracking_number'=>'SAFE-1','tracking_url'=>'https://example.test/safe'])->assertOk();
        Sanctum::actingAs($order->user);$response=$this->getJson('/api/v1/orders/'.$order->id.'/tracking')->assertOk()->assertJsonPath('delivery.courier.name','Courier Seguro')->assertJsonPath('delivery.courier.tracking_number','SAFE-1');
        $json=$response->getContent();$this->assertStringNotContainsString('recipient_document_number',$json);$this->assertStringNotContainsString('provider_metadata',$json);$this->assertStringNotContainsString('delivery_photo_path',$json);
    }

    public function test_legacy_delivered_order_remains_consultable_without_backfill(): void
    {
        $order=$this->readyOrder();$order->update(['delivery_flow_version'=>null,'fulfillment_status'=>'delivered','status'=>'delivered','tracking_status'=>'delivered','delivered_at'=>now()]);Sanctum::actingAs($order->user);
        $this->getJson('/api/v1/orders/'.$order->id.'/tracking')->assertOk();$this->assertDatabaseMissing('order_deliveries',['order_id'=>$order->id]);$this->assertDatabaseMissing('payment_transactions',['order_id'=>$order->id]);
    }

    public function test_financial_and_delivery_foreign_keys_protect_traceability_and_users_are_nullable(): void
    {
        $admin=$this->admin();$order=$this->readyOrder();$payment=app(OrderPaymentService::class)->confirmCashOnDeliveryCollection($order,$admin);$admin->delete();$this->assertNull($payment->refresh()->collected_by);
        $this->expectException(QueryException::class);$order->delete();
    }

    public function test_notification_hook_failure_does_not_rollback_confirmed_delivery(): void
    {
        [$order]=$this->orderOutForDelivery();Event::listen(OrderDeliveryEvent::class,fn()=>throw new \RuntimeException('Correo no disponible'));
        $this->postJson($this->url($order,'confirm'),$this->recipient()+['money_received'=>true])->assertOk();
        $this->assertSame('delivered',$order->refresh()->fulfillment_status);$this->assertDatabaseHas('payment_transactions',['order_id'=>$order->id,'status'=>'approved']);
    }

    public function test_delivery_history_is_immutable(): void
    {
        $order=$this->readyOrder();Sanctum::actingAs($this->admin());$this->initialize($order);$history=OrderDeliveryHistory::firstOrFail();
        try{$history->update(['observation'=>'alterado']);$this->fail('El historial permitió update.');}catch(\LogicException){$this->assertTrue(true);}
        $this->expectException(\LogicException::class);$history->delete();
    }

    private function orderOutForDelivery(): array{$admin=$this->admin();$driver=User::factory()->create(['role'=>'admin','can_deliver'=>true]);$order=$this->readyOrder();Sanctum::actingAs($admin);$this->initialize($order);$this->postJson($this->url($order,'assign-driver'),['delivery_user_id'=>$driver->id])->assertOk();$this->postJson($this->url($order,'dispatch'))->assertOk();$this->postJson($this->url($order,'attempts'))->assertCreated();$this->postJson($this->url($order,'out-for-delivery'))->assertOk();return[$order->refresh(),$order->delivery()->first()->attempts()->first()];}
    private function initialize(Order $order):void{$this->postJson($this->url($order,'initialize'))->assertCreated();}
    private function readyOrder(string $payment='contra_entrega'):Order{$customer=User::factory()->create(['role'=>'customer']);return Order::create(['user_id'=>$customer->id,'status'=>'shipped','total'=>'37.50','shipping_info'=>['address'=>'Av. Histórica 123','city'=>'Lima','phone'=>'999111222','reference'=>'Portón azul'],'payment_method'=>$payment,'payment_status'=>$payment==='contra_entrega'?'pending':'approved','paid_at'=>$payment==='contra_entrega'?null:now(),'delivery_type'=>'delivery','tracking_status'=>'shipped','fulfillment_status'=>'ready','delivery_flow_version'=>1,'ready_at'=>now()]);}
    private function recipient():array{return['recipient_name'=>'Ana Pérez','recipient_document_type'=>'DNI','recipient_document_number'=>'12345678','confirmation_method'=>'manual'];}
    private function url(Order $order,string $path):string{return '/api/v1/admin/orders/'.$order->id.'/delivery/'.$path;}
    private function admin():User{return User::factory()->create(['role'=>'admin']);}
}
