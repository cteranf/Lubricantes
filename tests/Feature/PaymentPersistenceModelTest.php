<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\PaymentPreference;
use App\Models\PaymentWebhookEvent;
use App\Models\User;
use App\Services\PaymentWebhookSignatureService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentPersistenceModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_preference_and_remote_payment_uniqueness_are_database_backed(): void
    {
        $order = $this->order();
        $preference = $this->preference($order);
        $this->expectException(QueryException::class);
        PaymentPreference::create(array_merge($preference->getAttributes(), ['id' => null, 'external_reference' => 'mp_other', 'idempotency_key' => 'pref_other', 'provider_preference_id' => 'pref_other']));
    }

    public function test_webhook_signature_uses_official_sdk_validator(): void
    {
        config()->set('payment.mercadopago.webhook_secret', 'test-webhook-secret');
        $timestamp = (string) time();
        $dataId = '123';
        $requestId = 'request-1';
        $signature = 'ts='.$timestamp.',v1='.hash_hmac('sha256', 'id:'.$dataId.';request-id:'.$requestId.';ts:'.$timestamp.';', 'test-webhook-secret');
        app(PaymentWebhookSignatureService::class)->validate($signature, $requestId, $dataId);
        $this->expectException(\MercadoPago\Exceptions\InvalidWebhookSignatureException::class);
        app(PaymentWebhookSignatureService::class)->validate('ts='.$timestamp.',v1=invalid', $requestId, $dataId);
    }

    public function test_webhook_idempotency_key_is_unique(): void
    {
        PaymentWebhookEvent::create(['gateway' => 'mercadopago', 'payload_hash' => hash('sha256', 'one'), 'idempotency_key' => 'event-one', 'status' => PaymentWebhookEvent::RECEIVED, 'received_at' => now()]);
        $this->expectException(QueryException::class);
        PaymentWebhookEvent::create(['gateway' => 'mercadopago', 'payload_hash' => hash('sha256', 'two'), 'idempotency_key' => 'event-one', 'status' => PaymentWebhookEvent::RECEIVED, 'received_at' => now()]);
    }

    private function order(): Order
    {
        return Order::create(['user_id' => User::factory()->create()->id, 'status' => 'pending', 'total' => 10, 'shipping_info' => [], 'payment_method' => 'card', 'payment_status' => 'pending', 'delivery_type' => 'delivery', 'tracking_status' => 'pending']);
    }

    private function preference(Order $order): PaymentPreference
    {
        return PaymentPreference::create(['order_id' => $order->id, 'gateway' => 'mercadopago', 'provider_preference_id' => 'pref_1', 'external_reference' => 'mp_ref_1', 'idempotency_key' => 'pref_key_1', 'state' => PaymentPreference::ACTIVE, 'creation_started_at' => now(), 'activated_at' => now()]);
    }
}
