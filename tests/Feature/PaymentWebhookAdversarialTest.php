<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\PaymentPreference;
use App\Services\PaymentGateway\PaymentGatewayInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PaymentWebhookAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private string $paymentId;
    private string $merchantId;
    private string $preferenceId;
    private string $reference;
    private string $eventId;
    private string $secret;

    protected function setUp(): void
    {
        parent::setUp();
        $this->paymentId = (string) random_int(1000000, 1000000000);
        $this->merchantId = (string) random_int(1000000001, 2000000000);
        $this->preferenceId = 'synthetic-'.bin2hex(random_bytes(16));
        $this->reference = 'synthetic-'.bin2hex(random_bytes(16));
        $this->eventId = 'synthetic-'.bin2hex(random_bytes(16));
        $this->secret = 'SYNTHETIC_'.bin2hex(random_bytes(16));
        config()->set('payment.mercadopago.webhook_secret', $this->secret);
        config()->set('payment.testing_legacy_webhook', false);
        config()->set('payment.default_gateway', 'mercadopago');
        config()->set('payment.mock.enabled', false);
        WebhookFakeGateway::$calls = 0;
        WebhookFakeGateway::$lastId = null;
        WebhookFakeGateway::$response = [];
        $this->app->instance(PaymentGatewayInterface::class, new WebhookFakeGateway);
    }

    public function test_valid_signature_uses_authenticated_data_id_and_server_response(): void
    {
        $user = \App\Models\User::factory()->create();
        Sanctum::actingAs($user);
        $order = Order::create(['user_id' => $user->id, 'status' => 'pending', 'total' => 10, 'shipping_info' => [], 'payment_method' => 'card', 'payment_status' => 'pending', 'delivery_type' => 'delivery', 'tracking_status' => 'pending']);
        $preference = PaymentPreference::create(['order_id' => $order->id, 'gateway' => 'mercadopago', 'provider_preference_id' => $this->preferenceId, 'external_reference' => $this->reference, 'idempotency_key' => $this->preferenceId, 'state' => PaymentPreference::ACTIVE, 'creation_started_at' => now()]);
        $order->update(['current_payment_preference_id' => $preference->id]);
        WebhookFakeGateway::$response = $this->remote('pending');
        $response = $this->signedPost($this->paymentId, ['status' => 'approved', 'transaction_amount' => 999, 'currency_id' => 'USD', 'external_reference' => 'wrong']);
        $response->assertOk();
        $this->assertSame($this->paymentId, WebhookFakeGateway::$lastId);
        $this->assertSame('pending', $order->refresh()->payment_status);
    }

    public function test_invalid_signature_never_creates_event_or_calls_gateway(): void
    {
        WebhookFakeGateway::$calls = 0;
        $this->postJson('/api/v1/payment/webhook', ['type' => 'payment', 'data' => ['id' => $this->paymentId]], ['x-signature' => 'ts=1,v1=bad', 'x-request-id' => 'req'])->assertUnauthorized();
        $this->assertDatabaseCount('payment_webhook_events', 0);
        $this->assertSame(0, WebhookFakeGateway::$calls);
    }

    public function test_missing_signature_is_rejected_outside_explicit_testing_fallback(): void
    {
        config()->set('payment.testing_legacy_webhook', false);
        $this->postJson('/api/v1/payment/webhook', ['type' => 'payment', 'data' => ['id' => $this->paymentId]])->assertUnauthorized();
    }

    public function test_legacy_fallback_requires_explicit_testing_flag(): void
    {
        config()->set('payment.default_gateway', 'mock');
        config()->set('payment.mock.enabled', true);
        config()->set('payment.testing_legacy_webhook', true);
        $this->postJson('/api/v1/payment/webhook', ['order_id' => 'missing-order', 'payment_id' => 'legacy-payment'])->assertOk();
        config()->set('payment.testing_legacy_webhook', false);
        $this->postJson('/api/v1/payment/webhook', ['order_id' => 'missing-order', 'payment_id' => 'legacy-payment', 'type' => 'payment', 'data' => ['id' => $this->paymentId]])->assertUnauthorized();
    }

    public function test_legacy_flag_does_not_accept_a_normal_request(): void
    {
        config()->set('payment.default_gateway', 'mock');
        config()->set('payment.mock.enabled', true);
        config()->set('payment.testing_legacy_webhook', true);

        $this->postJson('/api/v1/payment/webhook', ['order_id' => 'missing-order', 'type' => 'payment', 'data' => ['id' => $this->paymentId]])->assertUnauthorized();
        $this->postJson('/api/v1/payment/webhook', ['order_id' => 'missing-order', 'type' => 'payment', 'data' => ['id' => $this->paymentId]])->assertUnauthorized();
    }

    public function test_legacy_flag_is_ignored_outside_testing_environment(): void
    {
        config()->set('payment.default_gateway', 'mock');
        config()->set('payment.mock.enabled', true);
        config()->set('payment.testing_legacy_webhook', true);

        foreach (['local', 'staging', 'production'] as $environment) {
            $this->app->instance('env', $environment);
            $this->postJson('/api/v1/payment/webhook', ['order_id' => 'legacy-order', 'payment_id' => 'legacy-payment', 'type' => 'payment', 'data' => ['id' => $this->paymentId]])->assertUnauthorized();
        }
    }

    public function test_signature_timestamp_request_id_and_data_id_are_bound(): void
    {
        $timestamp = time() - 1000;
        $this->postJson('/api/v1/payment/webhook', ['type' => 'payment', 'data' => ['id' => $this->paymentId]], ['x-signature' => $this->signature($this->paymentId, 'req', $timestamp), 'x-request-id' => 'req'])->assertUnauthorized();
        $this->postJson('/api/v1/payment/webhook', ['type' => 'payment', 'data' => ['id' => $this->paymentId]], ['x-signature' => $this->signature($this->paymentId, 'req', time()), 'x-request-id' => 'other'])->assertUnauthorized();
        $this->postJson('/api/v1/payment/webhook', ['type' => 'payment', 'data' => ['id' => (string) ((int) $this->paymentId + 1)]], ['x-signature' => $this->signature($this->paymentId, 'req', time()), 'x-request-id' => 'req'])->assertUnauthorized();
    }

    public function test_malformed_signature_is_controlled(): void
    {
        $this->postJson('/api/v1/payment/webhook', ['type' => 'payment', 'data' => ['id' => $this->paymentId]], ['x-signature' => 'not-a-signature', 'x-request-id' => 'req'])->assertUnauthorized();
    }

    public function test_duplicate_provider_event_is_processed_once(): void
    {
        [$order] = $this->fixture();
        WebhookFakeGateway::$response = $this->remote('pending');
        $first = $this->signedPost($this->paymentId, ['id' => $this->eventId, 'type' => 'payment']);
        $first->assertOk();
        $this->signedPost($this->paymentId, ['id' => $this->eventId, 'type' => 'payment'])->assertOk();
        $this->assertDatabaseCount('payment_webhook_events', 1);
        $this->assertDatabaseCount('payment_transactions', 1);
        $this->assertSame(1, WebhookFakeGateway::$calls);
        $this->assertSame('pending', $order->refresh()->payment_status);
    }

    public function test_semantically_equal_event_without_provider_id_is_deduplicated(): void
    {
        $this->fixture();
        WebhookFakeGateway::$response = $this->remote('pending');
        $this->signedPost($this->paymentId, ['type' => 'payment', 'action' => 'updated'])->assertOk();
        $this->signedPost($this->paymentId, ['action' => 'updated', 'type' => 'payment'])->assertOk();
        $this->assertDatabaseCount('payment_webhook_events', 1);
        $this->assertSame(1, WebhookFakeGateway::$calls);
    }

    public function test_invalid_amount_and_currency_are_failed_without_approval(): void
    {
        [$order] = $this->fixture();
        WebhookFakeGateway::$response = array_merge($this->remote('approved'), ['transaction_amount' => 9, 'currency_id' => 'USD']);
        $this->signedPost($this->paymentId, ['type' => 'payment'])->assertStatus(422);
        $this->assertDatabaseHas('payment_webhook_events', ['status' => 'failed']);
        $this->assertDatabaseMissing('payment_transactions', ['status' => 'approved']);
        $this->assertSame('pending', $order->refresh()->payment_status);
    }

    public function test_approved_then_pending_does_not_degrade_payment(): void
    {
        [$order] = $this->fixture();
        WebhookFakeGateway::$response = $this->remote('approved');
        $this->signedPost($this->paymentId, ['id' => $this->eventId])->assertOk();
        $this->assertSame('approved', $order->refresh()->payment_status);
        WebhookFakeGateway::$response = $this->remote('pending');
        $this->signedPost($this->paymentId, ['id' => $this->eventId.'-later'])->assertOk();
        $this->assertSame('approved', $order->refresh()->payment_status);
        $this->assertDatabaseCount('payment_transactions', 1);
    }

    private function fixture(): array
    {
        $user = \App\Models\User::factory()->create();
        Sanctum::actingAs($user);
        $order = Order::create(['user_id' => $user->id, 'status' => 'pending', 'total' => 10, 'shipping_info' => [], 'payment_method' => 'card', 'payment_status' => 'pending', 'delivery_type' => 'delivery', 'tracking_status' => 'pending']);
        $preference = PaymentPreference::create(['order_id' => $order->id, 'gateway' => 'mercadopago', 'provider_preference_id' => $this->preferenceId, 'external_reference' => $this->reference, 'idempotency_key' => $this->preferenceId.$order->id, 'state' => PaymentPreference::ACTIVE, 'creation_started_at' => now()]);
        $order->update(['current_payment_preference_id' => $preference->id]);

        return [$order, $user];
    }

    private function remote(string $status): array
    {
        return ['gateway' => 'mercadopago', 'merchant_order_id' => $this->merchantId, 'id' => $this->paymentId, 'status' => $status, 'status_detail' => 'fixture', 'external_reference' => $this->reference, 'preference_id' => $this->preferenceId, 'transaction_amount' => 10, 'currency_id' => 'PEN'];
    }

    private function signedPost(string $id, array $body)
    {
        $body['data'] = ['id' => $id];
        $body['type'] ??= 'payment';

        return $this->postJson('/api/v1/payment/webhook', $body, ['x-signature' => $this->signature($id, 'req-fixture'), 'x-request-id' => 'req-fixture']);
    }

    private function signature(string $id, string $requestId, ?int $timestamp = null): string
    {
        $timestamp ??= time();

        return 'ts='.$timestamp.',v1='.hash_hmac('sha256', 'id:'.$id.';request-id:'.$requestId.';ts:'.$timestamp.';', $this->secret);
    }
}

class WebhookFakeGateway implements PaymentGatewayInterface
{
    public static int $calls = 0;

    public static ?string $lastId = null;

    public static array $response = [];

    public function createPayment(array $orderData): array
    {
        return [];
    }

    public function verifyPayment(string $paymentId): array
    {
        self::$calls++;
        self::$lastId = $paymentId;

        return self::$response ?: ['id' => $paymentId, 'status' => 'pending'];
    }

    public function findPreference(string $preferenceId): array
    {
        return [];
    }

    public function refundPayment(string $paymentId, float $amount): array
    {
        return [];
    }

    public function handleWebhook(array $payload): array
    {
        return [];
    }
}
