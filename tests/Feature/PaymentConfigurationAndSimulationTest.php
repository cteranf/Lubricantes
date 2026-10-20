<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\PaymentPreference;
use App\Models\PaymentSetting;
use App\Models\PaymentTransaction;
use App\Models\Product;
use App\Models\User;
use App\Services\InventoryService;
use App\Services\PaymentGateway\PaymentGatewayFactory;
use App\Services\PaymentSettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesShippingCoverage;
use Tests\TestCase;

class PaymentConfigurationAndSimulationTest extends TestCase
{
    use CreatesShippingCoverage, RefreshDatabase;

    public function test_mock_worker_get_preserves_the_exact_signed_query(): void
    {
        $url = URL::temporarySignedRoute('mock.payment', now()->addMinutes(30), ['paymentId' => 'SYNTHETIC-SIGNATURE', 'order_id' => 1]);
        $internalRole = 'second';
        $internalAction = 'get';
        $request = \Illuminate\Http\Request::create($url, 'GET');
        $this->assertTrue(URL::hasValidSignature($request), 'An intact signed GET must remain valid.');
        $this->assertTrue($internalRole === 'second' && $internalAction === 'get' && URL::hasValidSignature($request), 'Internal worker arguments must not alter the signed request.');
        foreach (['action' => 'approve', 'scenario' => 'approved'] as $key => $value) {
            $modified = \Illuminate\Http\Request::create($url, 'GET', [$key => $value]);
            $this->assertFalse(URL::hasValidSignature($modified), 'Adding unsigned GET parameters must invalidate the signature.');
        }
    }

    public function test_atomic_mock_get_preserves_approval_committed_before_lock(): void
    {
        $order = $this->orderFor($this->customer(), $this->product());
        $id = $order->currentPaymentPreference->provider_preference_id;
        $this->post(URL::temporarySignedRoute('mock.payment.process', now()->addMinutes(30), ['paymentId' => $id, 'order_id' => $order->id]), ['action' => 'approve'])->assertRedirect();
        $this->travel(31)->minutes();
        try {
            $before = $this->mockFinancialSnapshot($order);
            $this->get(URL::temporarySignedRoute('mock.payment', now()->addMinutes(30), ['paymentId' => $id, 'order_id' => $order->id]))->assertRedirect();
            $this->assertTrue($before === $this->mockFinancialSnapshot($order), 'Paid mock checkout must not change financial or inventory evidence.');
            $this->assertSame('approved', $order->refresh()->payment_status);
            $this->assertNotSame('canceled', $order->status);
        } finally {
            $this->travelBack();
        }
    }

    public function test_atomic_mock_get_expires_once_and_rolls_back_after_each_write(): void
    {
        $order = $this->orderFor($this->customer(), $this->product());
        $order->update(['reserved_until' => now()->subMinute()]);
        $order->reservations()->update(['expires_at' => now()->subMinute()]);
        $id = $order->currentPaymentPreference->provider_preference_id;
        $service = app(\App\Services\MockPaymentCheckoutService::class);
        $before = $this->mockFinancialSnapshot($order);
        $active = false;
        $failAt = null;
        $writes = 0;
        DB::listen(function ($query) use (&$active, &$failAt, &$writes) {
            if ($active && preg_match('/^\s*(insert|update|delete)\b/i', $query->sql)) {
                $writes++;
                if ($writes === $failAt) {
                    throw new \RuntimeException('synthetic-write-failure');
                }
            }
        });
        // Discover the real write boundary count within a transaction rolled back by the test.
        DB::beginTransaction();
        try {
            $active = true;
            $service->inspect($id, $order->id);
        } finally {
            $active = false;
            DB::rollBack();
        }
        $writeCount = $writes;
        $this->assertGreaterThanOrEqual(4, $writeCount);
        for ($failAt = 1; $failAt <= $writeCount; $failAt++) {
            $writes = 0;
            $thrown = false;
            try {
                $active = true;
                $service->inspect($id, $order->id);
            } catch (\RuntimeException $exception) {
                $thrown = $exception->getMessage() === 'synthetic-write-failure';
            } finally {
                $active = false;
            }
            $this->assertTrue($thrown, 'Each expiration write boundary must propagate the injected failure.');
            $this->assertTrue($before === $this->mockFinancialSnapshot($order), 'Failed expiration must roll back all evidence.');
        }
        $expired = $service->inspect($id, $order->id);
        $this->assertSame('canceled', $expired->status);
        $this->assertNull($expired->paid_at);
        $this->assertSame('expired', $order->reservations()->sole()->status);
        $after = $this->mockFinancialSnapshot($order);
        $service->inspect($id, $order->id);
        $this->assertTrue($after === $this->mockFinancialSnapshot($order), 'Repeated GET expiration must be idempotent.');
    }

    public function test_atomic_mock_get_skipped_expiration_does_not_cancel(): void
    {
        $order = $this->orderFor($this->customer(), $this->product());
        $order->update(['reserved_until' => now()->subMinute()]);
        $expiration = \Mockery::mock(\App\Services\InventoryReservationExpirationService::class);
        $expiration->shouldReceive('expireOrder')->once()->with($order->id)->andReturn(['processed' => false, 'reason' => 'paid_order_excluded']);
        $service = new \App\Services\MockPaymentCheckoutService(app(\App\Services\MockPaymentPreferenceResolver::class), $expiration);
        $before = $this->mockFinancialSnapshot($order);
        $service->inspect($order->currentPaymentPreference->provider_preference_id, $order->id);
        $this->assertTrue($before === $this->mockFinancialSnapshot($order), 'Skipped expiration must not cause a follow-up write.');
    }

    /** @dataProvider mockCurrencyRoutes */
    public function test_mock_currency_uses_configuration_and_order_authority(string $route): void
    {
        $order = $this->orderFor($this->customer(), $this->product());
        $original = config('payment.currency');
        try {
            config(['payment.currency' => 'USD']);
            $url = URL::temporarySignedRoute($route, now()->addMinutes(30), ['paymentId' => $order->currentPaymentPreference->provider_preference_id, 'order_id' => $order->id]);
            $this->post($url, ['action' => 'approve', 'currency' => 'EUR', 'amount' => 1])->assertRedirect();
            $transaction = PaymentTransaction::where('order_id', $order->id)->sole();
            $this->assertSame('USD', $transaction->currency);
            $this->assertTrue($transaction->amount === $order->total, 'The order supplies the mock amount; no preference financial snapshot exists.');
            $before = $this->mockFinancialSnapshot($order);
            $this->post($url, ['action' => 'approve'])->assertRedirect();
            $this->assertTrue($before === $this->mockFinancialSnapshot($order), 'Currency retry must preserve the original payment.');
            $other = $this->orderFor($this->customer(), $this->product());
            $real = DB::transaction(fn () => app(\App\Services\OrderPaymentService::class)->recordCardAttempt($other, 'SYNTHETIC-REAL-CONTRACT', PaymentTransaction::APPROVED));
            $this->assertSame('PEN', $real->currency, 'Existing non-mock callers retain their currency contract without a provider call.');
        } finally {
            config(['payment.currency' => $original]);
        }
    }

    public static function mockCurrencyRoutes(): array
    {
        return [['mock.payment.process'], ['mock.payment.approve']];
    }

    public function test_mock_currency_invalid_configuration_fails_before_writes(): void
    {
        $order = $this->orderFor($this->customer(), $this->product());
        $original = config('payment.currency');
        try {
            foreach ([null, '', 'usd', 'US', 'USD-extra'] as $invalid) {
                config(['payment.currency' => $invalid]);
                $before = $this->mockFinancialSnapshot($order);
                $thrown = false;
                try {
                    app(\App\Services\OrderPaymentService::class)->recordMockCardAttempt($order, 'SYNTHETIC-CONFIG', PaymentTransaction::APPROVED);
                } catch (\InvalidArgumentException) {
                    $thrown = true;
                }
                $this->assertTrue($thrown && $before === $this->mockFinancialSnapshot($order), 'Invalid mock currency must fail without financial writes.');
            }
        } finally {
            config(['payment.currency' => $original]);
        }
    }

    private function mockFinancialSnapshot(Order $order): string
    {
        $snapshot = [];
        foreach (['orders', 'payment_preferences', 'payment_transactions', 'inventory_reservations', 'warehouse_inventories', 'inventory_movements', 'order_fulfillment_histories', 'products'] as $table) {
            $snapshot[$table] = DB::table($table)->orderBy('id')->get()->toArray();
        }

        return json_encode($snapshot, JSON_THROW_ON_ERROR);
    }

    /** The mock uses Order.total and payment.currency, not a preference financial snapshot. */
    public function test_current_mock_preference_renders_order_authority_without_legacy_payment_id(): void
    {
        $order = $this->orderFor($this->customer(), $this->product());
        $preference = $order->currentPaymentPreference;
        $this->assertTrue($preference->gateway === 'mock' && $preference->state === PaymentPreference::ACTIVE);
        $this->assertSame($order->id, $preference->order_id);
        $this->assertNull($order->payment_id);
        $url = URL::temporarySignedRoute('mock.payment', now()->addMinutes(30), ['paymentId' => $preference->provider_preference_id, 'order_id' => $order->id]);
        $response = $this->get($url)->assertOk()->assertViewIs('mock-payment');
        $this->assertTrue($response->viewData('order')->is($order));
        $this->assertSame(config('payment.currency'), $response->viewData('currency'));
        $this->assertTrue(str_contains($response->getContent(), 'Pagar '.config('payment.currency').' '.number_format($order->total, 2)), 'Mock rendering must use order and configuration authority.');
        $this->assertNull($order->refresh()->payment_id);
    }

    /** @dataProvider invalidMockLinks */
    public function test_current_mock_resolution_rejects_incoherent_links_without_writes(string $route, string $anomaly): void
    {
        $order = $this->orderFor($this->customer(), $this->product());
        $preference = $order->currentPaymentPreference;
        $paymentId = $preference->provider_preference_id;
        $signedOrder = $order->id;
        switch ($anomaly) {
            case 'crossed-order':
                $other = $this->orderFor($this->customer(), $this->product());
                $signedOrder = $other->id;
                break;
            case 'crossed-preference':
                $other = $this->orderFor($this->customer(), $this->product());
                $order->update(['current_payment_preference_id' => $other->current_payment_preference_id]);
                break;
            case 'historical':
                $preference->update(['state' => PaymentPreference::SUPERSEDED]);
                app(\App\Services\PaymentPreferenceService::class)->createFor($order, ['order_id' => $order->id], 'mock');
                break;
            case 'missing-current': $order->update(['current_payment_preference_id' => null]);
                break;
            case 'gateway': $preference->update(['gateway' => 'mercadopago']);
                break;
            case 'missing-preference': $paymentId = 'SYNTHETIC-MISSING';
                break;
            case 'missing-order': $signedOrder = 999999;
                break;
            case 'failed': $preference->update(['state' => PaymentPreference::FAILED]);
                break;
            case 'orphaned': $preference->update(['state' => PaymentPreference::ORPHANED]);
                break;
            case 'creating': $preference->update(['state' => PaymentPreference::CREATING]);
                break;
            case 'substituted': $preference->update(['state' => PaymentPreference::SUPERSEDED]);
                break;
            case 'wrong-method': $order->update(['payment_method' => 'transferencia']);
                break;
            case 'refund-state': $order->update(['payment_status' => 'refund_pending']);
                break;
        }
        // A crossed or stale link must fail before the GET expiration side effect too.
        $order->update(['reserved_until' => now()->subMinute()]);
        $writes = 0;
        DB::listen(function ($query) use (&$writes) {
            if (preg_match('/^\s*(insert|update|delete|replace|alter|drop|create)\b/i', $query->sql)) {
                $writes++;
            }
        });
        $url = URL::temporarySignedRoute($route, now()->addMinutes(30), ['paymentId' => $paymentId, 'order_id' => $signedOrder]);
        $response = $route === 'mock.payment' ? $this->get($url) : $this->post($url, ['action' => 'approve']);
        $this->assertSame(404, $response->getStatusCode(), 'Incoherent mock identities must fail privately.');
        $this->assertSame(0, $writes, 'Rejected mock identities must not write.');
    }

    public static function invalidMockLinks(): array
    {
        $cases = [];
        foreach (['mock.payment', 'mock.payment.process', 'mock.payment.approve'] as $route) {
            foreach (['crossed-order', 'crossed-preference', 'historical', 'missing-current', 'gateway', 'missing-preference', 'missing-order', 'failed', 'orphaned', 'creating', 'substituted', 'wrong-method', 'refund-state'] as $anomaly) {
                $cases[$route.' '.$anomaly] = [$route, $anomaly];
            }
        }

        return $cases;
    }

    /** @dataProvider mockRouteGuards */
    public function test_current_mock_route_environment_and_signature_guards(string $route, string $environment, bool $enabled, string $signature, int $expected): void
    {
        $order = $this->orderFor($this->customer(), $this->product());
        $url = URL::temporarySignedRoute($route, $signature === 'expired' ? now()->subMinute() : now()->addMinutes(30), ['paymentId' => $order->currentPaymentPreference->provider_preference_id, 'order_id' => $order->id]);
        if ($signature === 'invalid') {
            $url = preg_replace('/signature=[^&]+/', 'signature=invalid', $url);
        }
        $this->withMockEnvironment($environment, $enabled, function () use ($route, $url, $expected) {
            $token = Str::random(40);
            $this->withSession(['_token' => $token]);
            $response = $route === 'mock.payment' ? $this->get($url) : $this->post($url, ['_token' => $token, 'action' => 'reject', 'scenario' => 'declined']);
            $this->assertSame($expected, $response->getStatusCode(), 'Mock environment and signature guards must remain effective.');
        });
    }

    public static function mockRouteGuards(): array
    {
        $cases = [];
        foreach (['mock.payment', 'mock.payment.process', 'mock.payment.approve'] as $route) {
            foreach ([['local', false, 'valid', 404], ['testing', false, 'valid', 404], ['production', true, 'valid', 404], ['local', true, 'valid', $route === 'mock.payment' ? 200 : 302], ['testing', true, 'valid', $route === 'mock.payment' ? 200 : 302], ['local', true, 'invalid', 403], ['local', true, 'expired', 403], ['testing', true, 'invalid', 403], ['testing', true, 'expired', 403]] as $case) {
                $cases[$route.' '.implode('-', array_map(fn ($value) => (string) $value, $case))] = [$route, ...$case];
            }
        }

        return $cases;
    }

    /** @dataProvider invalidMockCsrf */
    public function test_mock_posts_require_csrf_before_mock_or_signature_guards(string $route, bool $missing): void
    {
        $order = $this->orderFor($this->customer(), $this->product());
        $url = URL::temporarySignedRoute($route, now()->addMinutes(30), ['paymentId' => $order->currentPaymentPreference->provider_preference_id, 'order_id' => $order->id]);
        $this->withMockEnvironment('local', true, function () use ($url, $missing) {
            $this->withSession(['_token' => Str::random(40)]);
            $payload = ['action' => 'approve'];
            if (! $missing) {
                $payload['_token'] = 'synthetic-mismatched-csrf';
            }
            $response = $this->post($url, $payload);
            $this->assertSame(419, $response->getStatusCode(), 'Mock POST routes must retain CSRF protection.');
        });
    }

    public static function invalidMockCsrf(): array
    {
        return [
            'process missing' => ['mock.payment.process', true],
            'process mismatched' => ['mock.payment.process', false],
            'approve missing' => ['mock.payment.approve', true],
            'approve mismatched' => ['mock.payment.approve', false],
        ];
    }

    private function withMockEnvironment(string $environment, bool $enabled, callable $callback): void
    {
        $originalEnvironment = $this->app->environment();
        $originalEnabled = config('payment.mock.enabled');
        $session = $this->app['session']->driver();
        $originalSession = $session->all();
        try {
            $this->app->detectEnvironment(fn () => $environment);
            config(['payment.mock.enabled' => $enabled]);
            $callback();
        } finally {
            $session->flush();
            $session->put($originalSession);
            config(['payment.mock.enabled' => $originalEnabled]);
            $this->app->detectEnvironment(fn () => $originalEnvironment);
        }
    }

    public function test_current_mock_approve_is_idempotent_without_legacy_payment_id(): void
    {
        $order = $this->orderFor($this->customer(), $this->product());
        $url = URL::temporarySignedRoute('mock.payment.approve', now()->addMinutes(30), ['paymentId' => $order->currentPaymentPreference->provider_preference_id, 'order_id' => $order->id]);
        $this->post($url)->assertRedirect('/orders/success/'.$order->id);
        $before = $order->refresh()->getAttributes();
        $transactions = PaymentTransaction::where('order_id', $order->id)->count();
        $movements = DB::table('inventory_movements')->count();
        $history = $order->fulfillmentHistory()->count();
        $this->post($url)->assertRedirect('/orders/success/'.$order->id);
        $this->assertTrue($before === $order->refresh()->getAttributes(), 'Replay must preserve the paid order.');
        $this->assertSame(1, $transactions);
        $this->assertSame($transactions, PaymentTransaction::where('order_id', $order->id)->count());
        $this->assertSame($movements, DB::table('inventory_movements')->count());
        $this->assertSame($history, $order->fulfillmentHistory()->count());
        $this->assertSame('approved', $order->payment_status);
        $this->assertNull($order->payment_id);
        $this->assertTrue(PaymentTransaction::where('order_id', $order->id)->first()->amount === $order->total);
    }

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('payment.mock.enabled', true);
        config()->set('payment.default_gateway', 'mock');
    }

    public function test_public_methods_endpoint_returns_sanitized_methods_by_delivery_type(): void
    {
        $deliveryMethods = $this->getJson('/api/v1/payment-methods?delivery_type=delivery')
            ->assertOk()
            ->json('methods');

        $deliveryValues = array_column($deliveryMethods, 'value');
        $this->assertContains('card', $deliveryValues);
        $this->assertContains('transferencia', $deliveryValues);
        $this->assertContains('contra_entrega', $deliveryValues);
        $this->assertNotContains('pago_en_sede', $deliveryValues);

        $pickupMethods = $this->getJson('/api/v1/payment-methods?delivery_type=pickup')
            ->assertOk()
            ->json('methods');

        $pickupValues = array_column($pickupMethods, 'value');
        $this->assertContains('card', $pickupValues);
        $this->assertContains('transferencia', $pickupValues);
        $this->assertContains('pago_en_sede', $pickupValues);
        $this->assertNotContains('contra_entrega', $pickupValues);
    }

    public function test_mock_gateway_is_blocked_in_production_environment(): void
    {
        $this->withMockEnvironment('production', true, function () {
            $service = app(PaymentSettingService::class);
            $this->assertFalse($service->isMockActive());
            $this->expectException(\RuntimeException::class);
            PaymentGatewayFactory::create('mock');
        });
    }

    public function test_secret_access_token_is_never_exposed_and_empty_update_preserves_existing(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);

        // 1. Configure secret token
        $this->putJson('/api/v1/admin/payment-settings', [
            'card_enabled' => true,
            'card_gateway' => 'mercadopago',
            'mercadopago_public_key' => 'TEST-PUBLIC-KEY-123',
            'mercadopago_access_token' => 'TEST-SECRET-TOKEN-REAL-999',
        ])->assertOk();

        // 2. Fetch admin settings - token must be masked, not raw
        $getRes = $this->getJson('/api/v1/admin/payment-settings')->assertOk();
        $this->assertSame('TEST-PUBLIC-KEY-123', $getRes->json('mercadopago_public_key'));
        $this->assertTrue($getRes->json('has_mercadopago_access_token'));
        $this->assertStringNotContainsString('TEST-SECRET-TOKEN-REAL-999', $getRes->getContent());
        $this->assertStringContainsString('••••', $getRes->json('mercadopago_access_token_masked'));

        // 3. Update without sending token (or sending masked placeholder)
        $this->putJson('/api/v1/admin/payment-settings', [
            'card_title' => 'Nuevo Título Tarjeta',
            'mercadopago_access_token' => 'APP_USR-••••••••••••••••',
        ])->assertOk();

        // 4. Verify original encrypted token is intact in DB
        $setting = PaymentSetting::current();
        $this->assertSame('Nuevo Título Tarjeta', $setting->card_title);
        $this->assertSame('TEST-SECRET-TOKEN-REAL-999', $setting->mercadopago_access_token);
    }

    public function test_contraentrega_is_rejected_for_pickup_and_pago_en_sede_is_rejected_for_delivery(): void
    {
        $customer = $this->customer();
        $product = $this->product();
        Sanctum::actingAs($customer);

        // Attempt contra_entrega on pickup
        $pickupPayload = [
            'delivery_type' => 'pickup',
            'pickup_branch_id' => $this->branch()->id,
            'payment_method' => 'contra_entrega',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ];
        $this->postJson('/api/v1/orders', $pickupPayload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payment_method');

        // Attempt pago_en_sede on delivery
        $deliveryPayload = $this->withShippingCoverage([
            'delivery_type' => 'delivery',
            'payment_method' => 'pago_en_sede',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ]);
        $this->postJson('/api/v1/orders', $deliveryPayload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payment_method');
    }

    public function test_failed_payment_keeps_order_and_reservation_intact_and_records_failed_transaction(): void
    {
        $customer = $this->customer();
        $product = $this->product(['stock' => 5]);
        $order = $this->orderFor($customer, $product, ['payment_method' => 'card']);

        $url = URL::temporarySignedRoute('mock.payment.process', now()->addMinutes(30), [
            'paymentId' => $order->currentPaymentPreference->provider_preference_id,
            'order_id' => $order->id,
        ]);

        // Submit insufficient funds rejection
        $this->post($url, [
            'scenario' => 'insufficient_funds',
            'action' => 'reject',
        ])->assertRedirect('/orders/payment-return?payment_id='.$order->currentPaymentPreference->provider_preference_id.'&external_reference='.$order->id.'&result=rejected');

        $order->refresh();
        $this->assertSame('pending', $order->status);
        $this->assertSame('rejected', $order->payment_status);
        $this->assertNull($order->paid_at);

        // Reservation remains active
        $this->assertDatabaseHas('inventory_reservations', [
            'order_id' => $order->id,
            'status' => 'active',
        ]);

        // Failed transaction is recorded
        $this->assertDatabaseHas('payment_transactions', [
            'order_id' => $order->id,
            'status' => PaymentTransaction::FAILED,
            'payment_method' => 'card',
        ]);
    }

    public function test_subsequent_retry_can_be_approved_atomically_and_consumes_stock_reservation(): void
    {
        $customer = $this->customer();
        $product = $this->product(['stock' => 5]);
        $order = $this->orderFor($customer, $product, [
            'payment_method' => 'card',
            'payment_status' => 'rejected',
            'status' => 'pending',
        ]);

        $url = URL::temporarySignedRoute('mock.payment.process', now()->addMinutes(30), [
            'paymentId' => $order->currentPaymentPreference->provider_preference_id,
            'order_id' => $order->id,
        ]);

        // Submit approval
        $this->post($url, [
            'scenario' => 'approved',
            'action' => 'approve',
        ])->assertRedirect('/orders/payment-return?payment_id='.$order->currentPaymentPreference->provider_preference_id.'&external_reference='.$order->id.'&result=approved');

        $order->refresh();
        $this->assertSame('confirmed', $order->status);
        $this->assertSame('confirmed', $order->tracking_status);
        $this->assertSame('approved', $order->payment_status);
        $this->assertNotNull($order->paid_at);

        // Reservation is consumed
        $this->assertDatabaseHas('inventory_reservations', [
            'order_id' => $order->id,
            'status' => 'consumed',
        ]);

        // Approved transaction recorded
        $this->assertDatabaseHas('payment_transactions', [
            'order_id' => $order->id,
            'status' => PaymentTransaction::APPROVED,
            'approved_scope_key' => PaymentTransaction::approvedScopeKeyForOrder($order->id),
        ]);

        // Fulfillment history recorded
        $this->assertDatabaseHas('order_fulfillment_histories', [
            'order_id' => $order->id,
            'to_status' => Order::FULFILLMENT_PREPARING,
        ]);
    }

    public function test_repeated_approval_is_idempotent_and_never_duplicates_stock_movements_or_transactions(): void
    {
        $customer = $this->customer();
        $product = $this->product(['stock' => 5]);
        $order = $this->orderFor($customer, $product, ['payment_method' => 'card']);

        $url = URL::temporarySignedRoute('mock.payment.process', now()->addMinutes(30), [
            'paymentId' => $order->currentPaymentPreference->provider_preference_id,
            'order_id' => $order->id,
        ]);

        // 1st Approval
        $this->post($url, ['scenario' => 'approved', 'action' => 'approve'])->assertRedirect();
        $transactionCount = PaymentTransaction::where('order_id', $order->id)->count();
        $historyCount = $order->fulfillmentHistory()->count();

        // 2nd Approval (Replay)
        $this->post($url, ['scenario' => 'approved', 'action' => 'approve'])->assertRedirect();

        // Must not duplicate
        $this->assertSame($transactionCount, PaymentTransaction::where('order_id', $order->id)->count());
        $this->assertSame($historyCount, $order->fulfillmentHistory()->count());
    }

    public function test_expired_signed_route_is_rejected(): void
    {
        $customer = $this->customer();
        $product = $this->product();
        $order = $this->orderFor($customer, $product, ['payment_method' => 'card']);

        // Expired 5 minutes ago
        $expiredUrl = URL::temporarySignedRoute('mock.payment.process', now()->subMinutes(5), [
            'paymentId' => $order->currentPaymentPreference->provider_preference_id,
            'order_id' => $order->id,
        ]);

        $this->post($expiredUrl, ['action' => 'approve'])->assertForbidden();
    }

    public function test_payment_creation_is_blocked_when_card_method_is_disabled(): void
    {
        $customer = $this->customer();
        $product = $this->product();
        $order = $this->orderFor($customer, $product, ['payment_method' => 'card']);
        Sanctum::actingAs($customer);

        // Disable card in settings
        PaymentSetting::current()->update(['card_enabled' => false]);

        $this->postJson('/api/v1/payment/create', ['order_id' => $order->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('order_id');
    }

    public function test_payment_creation_is_blocked_on_already_paid_order(): void
    {
        $customer = $this->customer();
        $product = $this->product();
        $order = $this->orderFor($customer, $product, [
            'payment_method' => 'card',
            'payment_status' => 'approved',
            'paid_at' => now(),
        ]);
        Sanctum::actingAs($customer);

        $this->postJson('/api/v1/payment/create', ['order_id' => $order->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('order_id');
    }

    public function test_admin_cannot_disable_all_payment_methods(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);

        $this->putJson('/api/v1/admin/payment-settings', [
            'card_enabled' => false,
            'transfer_enabled' => false,
            'cash_on_delivery_enabled' => false,
            'cash_at_pickup_enabled' => false,
        ])->assertUnprocessable()->assertJsonValidationErrors('payment_settings');
    }

    public function test_no_raw_pan_cvv_or_card_data_is_stored_in_database(): void
    {
        $customer = $this->customer();
        $product = $this->product();
        $order = $this->orderFor($customer, $product, ['payment_method' => 'card']);

        $url = URL::temporarySignedRoute('mock.payment.process', now()->addMinutes(30), [
            'paymentId' => $order->currentPaymentPreference->provider_preference_id,
            'order_id' => $order->id,
        ]);

        $this->post($url, [
            'scenario' => 'approved',
            'action' => 'approve',
            // Even if client attempts to send card inputs, server ignores them
            'card_number' => '4557888899990000',
            'cvv' => '999',
            'expiry' => '12/28',
        ])->assertRedirect();

        $transaction = PaymentTransaction::where('order_id', $order->id)->first();
        $this->assertNotNull($transaction);
        $metadataString = json_encode($transaction->metadata);
        $this->assertStringNotContainsString('4557888899990000', $metadataString);
        $this->assertStringNotContainsString('999', $metadataString);
    }

    private function customer(): User
    {
        return User::factory()->create(['role' => 'customer']);
    }

    private function branch(): \App\Models\Branch
    {
        return \App\Models\Branch::create([
            'code' => 'SEDE-'.Str::random(4),
            'name' => 'Sede Principal',
            'address' => 'Av. Principal 123',
            'department' => 'Lima',
            'province' => 'Lima',
            'district' => 'Lima',
            'allows_pickup' => true,
            'serves_public' => true,
            'is_active' => true,
        ]);
    }

    private function product(array $attributes = []): Product
    {
        $data = array_merge([
            'name' => 'Lubricante '.Str::random(5),
            'slug' => 'lubricante-'.Str::uuid(),
            'sku' => 'LUB-'.Str::upper(Str::random(6)),
            'price' => 50,
            'stock' => 10,
            'is_active' => true,
        ], $attributes);
        $stock = (int) $data['stock'];
        unset($data['stock']);
        $product = Product::create($data);
        $inventory = app(InventoryService::class);
        $inventory->initializeProduct($product, $stock, $inventory->defaultWarehouse());

        return $product->refresh();
    }

    private function orderFor(User $user, Product $product, array $attributes = []): Order
    {
        $order = Order::create(array_merge([
            'user_id' => $user->id,
            'status' => 'pending',
            'subtotal' => $product->price,
            'shipping_amount' => 0,
            'total' => $product->price,
            'shipping_info' => ['address' => 'Av. Test 123', 'phone' => '999999999'],
            'payment_method' => 'card',
            'payment_status' => 'pending',
            'delivery_type' => 'delivery',
            'tracking_status' => 'pending',
            'reserved_until' => now()->addMinutes(30),
        ], $attributes));

        $item = $order->items()->create([
            'product_id' => $product->id,
            'warehouse_id' => app(InventoryService::class)->defaultWarehouse()->id,
            'quantity' => 1,
            'price' => $product->price,
            'subtotal' => $product->price,
        ]);
        $item->setRelation('product', $product);
        $item->setRelation('warehouse', app(InventoryService::class)->defaultWarehouse());
        app(InventoryService::class)->reserveForOrder($item, $order->reserved_until);

        app(\App\Services\PaymentPreferenceService::class)->createFor($order, ['order_id' => $order->id], 'mock');

        return $order->refresh();
    }
}
