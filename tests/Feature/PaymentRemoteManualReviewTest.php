<?php

namespace Tests\Feature;

use App\Models\PaymentPreference;
use App\Models\PaymentTransaction;
use App\Models\Product;
use App\Services\InventoryService;
use App\Services\MoneyService;
use App\Services\OrderPaymentService;
use App\Services\OrderStateService;
use App\Services\PaymentGateway\MercadoPagoGateway;
use App\Services\PaymentProcessingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\SyntheticMercadoPagoLink;
use Tests\TestCase;

class PaymentRemoteManualReviewTest extends TestCase
{
    use RefreshDatabase;
    use SyntheticMercadoPagoLink;

    private string $secret;

    protected function setUp(): void
    {
        parent::setUp();
        $this->secret = 'SYNTHETIC_'.bin2hex(random_bytes(16));
        config(['payment.testing_legacy_webhook' => false, 'payment.mercadopago.webhook_secret' => $this->secret,
            'payment.mock.enabled' => false, 'app.debug' => false]);
    }

    /** @dataProvider reversalStatuses */
    public function test_approved_order_reversals_are_terminal_manual_review_events(string $status): void
    {
        $this->withInventoryFixture(function ($transport, $order, $preference, $product) use ($status) {
            $this->approveFirstPayment($transport, $order, $preference);
            $transport->payment['status'] = $status;
            $before = $this->financialSnapshot($order, $preference, $product);
            $beforeCalls = count($transport->calls);
            $headers = $this->signedHeaders((string) $transport->paymentId);
            $body = $this->body((string) $transport->paymentId);

            $response = $this->postJson('/api/v1/payment/webhook', $body, $headers);

            $this->assertSame(202, $response->status());
            $this->assertSame(['status' => 'review_required', 'code' => 'manual_reconciliation_required'], $response->json());
            $event = \App\Models\PaymentWebhookEvent::sole();
            $this->assertSame('review_required', $event->status);
            $this->assertSame('manual_reconciliation_required', $event->failure_reason);
            $this->assertSame($before, $this->financialSnapshot($order, $preference, $product));
            $this->assertSame($beforeCalls + 2, count($transport->calls));

            $repeated = $this->postJson('/api/v1/payment/webhook', $body, $headers);
            $this->assertSame(202, $repeated->status());
            $this->assertSame($response->json(), $repeated->json());
            $this->assertSame($before, $this->financialSnapshot($order, $preference, $product));
            $this->assertSame($beforeCalls + 2, count($transport->calls));
            $this->assertSame(1, \App\Models\PaymentWebhookEvent::count());
            $this->assertSame(0, \App\Models\PaymentWebhookEvent::where('status', 'processed')->count());
        });
    }

    public static function reversalStatuses(): array
    {
        return [['refunded'], ['charged_back'], ['cancelled']];
    }

    /** @dataProvider unknownStatuses */
    public function test_unknown_remote_status_is_terminal_review_for_pending_or_approved_order(string $orderState, bool $approveFirst): void
    {
        $this->withInventoryFixture(function ($transport, $order, $preference, $product) use ($approveFirst, $orderState) {
            if ($approveFirst) {
                $this->approveFirstPayment($transport, $order, $preference);
            }
            $transport->payment['status'] = 'synthetic_unknown_status';
            $before = $this->financialSnapshot($order, $preference, $product);
            $beforeCalls = count($transport->calls);
            $headers = $this->signedHeaders((string) $transport->paymentId);
            $body = $this->body((string) $transport->paymentId);

            $response = $this->postJson('/api/v1/payment/webhook', $body, $headers);

            $this->assertSame(202, $response->status());
            $this->assertSame(['status' => 'review_required', 'code' => 'unsupported_remote_status'], $response->json());
            $event = \App\Models\PaymentWebhookEvent::sole();
            $this->assertSame('review_required', $event->status);
            $this->assertSame('unsupported_remote_status', $event->failure_reason);
            $this->assertSame($orderState, $order->refresh()->payment_status);
            $this->assertSame($before, $this->financialSnapshot($order, $preference, $product));
            $this->assertSame($beforeCalls + 2, count($transport->calls));

            $repeated = $this->postJson('/api/v1/payment/webhook', $body, $headers);
            $this->assertSame(202, $repeated->status());
            $this->assertSame($response->json(), $repeated->json());
            $this->assertSame($before, $this->financialSnapshot($order, $preference, $product));
            $this->assertSame($beforeCalls + 2, count($transport->calls));
            $this->assertSame(1, \App\Models\PaymentWebhookEvent::count());
            $this->assertSame(0, \App\Models\PaymentWebhookEvent::where('status', 'processed')->count());
        });
    }

    public static function unknownStatuses(): array
    {
        return [['pending', false], ['approved', true]];
    }

    /** @dataProvider staleApprovedLinks */
    public function test_approved_order_with_absent_or_substituted_current_preference_requires_review(string $case): void
    {
        $this->withInventoryFixture(function ($transport, $order, $preference, $product) use ($case) {
            $this->approveFirstPayment($transport, $order, $preference);
            if ($case === 'superseded') {
                $preference->update(['state' => PaymentPreference::SUPERSEDED, 'superseded_at' => now()]);
            } elseif ($case === 'missing_current_pointer') {
                $order->update(['current_payment_preference_id' => null]);
            } elseif ($case === 'gateway_mismatch') {
                $preference->update(['gateway' => 'mock']);
            } else {
                $preference->update(['external_reference' => 'synthetic-mismatched-'.bin2hex(random_bytes(8))]);
            }
            $before = $this->financialSnapshot($order, $preference, $product);
            $beforeCalls = count($transport->calls);
            $headers = $this->signedHeaders((string) $transport->paymentId);
            $body = $this->body((string) $transport->paymentId);

            $response = $this->postJson('/api/v1/payment/webhook', $body, $headers);

            $this->assertSame(202, $response->status());
            $this->assertSame(['status' => 'review_required', 'code' => 'financial_review_required'], $response->json());
            $event = \App\Models\PaymentWebhookEvent::sole();
            $this->assertSame('review_required', $event->status);
            $this->assertSame('financial_review_required', $event->failure_reason);
            $this->assertSame($before, $this->financialSnapshot($order, $preference, $product));
            $this->assertSame($beforeCalls + 2, count($transport->calls));

            $repeated = $this->postJson('/api/v1/payment/webhook', $body, $headers);
            $this->assertSame(202, $repeated->status());
            $this->assertSame($response->json(), $repeated->json());
            $this->assertSame($before, $this->financialSnapshot($order, $preference, $product));
            $this->assertSame($beforeCalls + 2, count($transport->calls));
            $this->assertSame(1, \App\Models\PaymentWebhookEvent::count());
            $this->assertSame(0, \App\Models\PaymentWebhookEvent::where('status', 'processed')->count());
        });
    }

    public static function staleApprovedLinks(): array
    {
        return [['superseded'], ['missing_current_pointer'], ['gateway_mismatch'], ['reference_mismatch']];
    }

    public function test_approved_order_link_replaced_after_localization_requires_review(): void
    {
        $this->withInventoryFixture(function ($transport, $order, $preference, $product) {
            $this->approveFirstPayment($transport, $order, $preference);
            $before = $this->financialSnapshot($order, $preference, $product);
            $preferenceId = (int) $preference->id;
            $this->app->instance(PaymentProcessingService::class, new class($preferenceId, app(MoneyService::class), app(OrderStateService::class), app(OrderPaymentService::class), app(InventoryService::class)) extends PaymentProcessingService
            {
                public function __construct(private int $preferenceId, MoneyService $money, OrderStateService $states, OrderPaymentService $payments, InventoryService $inventory)
                {
                    parent::__construct($money, $states, $payments, $inventory);
                }

                protected function afterRemoteOrderLock(\App\Models\Order $order): void
                {
                    PaymentPreference::whereKey($this->preferenceId)->update(['state' => PaymentPreference::SUPERSEDED, 'superseded_at' => now()]);
                    $order->update(['current_payment_preference_id' => null]);
                }
            });
            $headers = $this->signedHeaders((string) $transport->paymentId);
            $body = $this->body((string) $transport->paymentId);
            $beforeCalls = count($transport->calls);

            $response = $this->postJson('/api/v1/payment/webhook', $body, $headers);

            $this->assertSame(202, $response->status());
            $this->assertSame(['status' => 'review_required', 'code' => 'financial_review_required'], $response->json());
            $this->assertSame('review_required', \App\Models\PaymentWebhookEvent::sole()->status);
            $this->assertSame($before, $this->financialSnapshot($order, $preference, $product), 'The local interleaving must roll back with the review decision.');
            $this->assertSame($beforeCalls + 2, count($transport->calls));
        });
    }

    /** @dataProvider pendingStaleLinks */
    public function test_pending_order_with_stale_local_preference_fails_closed(string $case): void
    {
        $this->withInventoryFixture(function ($transport, $order, $preference, $product) use ($case) {
            if ($case === 'superseded') {
                $preference->update(['state' => PaymentPreference::SUPERSEDED, 'superseded_at' => now()]);
            } elseif ($case === 'missing_current_pointer') {
                $order->update(['current_payment_preference_id' => null]);
            } elseif ($case === 'gateway_mismatch') {
                $preference->update(['gateway' => 'mock']);
            } else {
                $preference->update(['external_reference' => 'synthetic-mismatched-'.bin2hex(random_bytes(8))]);
            }
            $before = $this->financialSnapshot($order, $preference, $product);
            $response = $this->postJson('/api/v1/payment/webhook', $this->body((string) $transport->paymentId), $this->signedHeaders((string) $transport->paymentId));

            $this->assertSame(422, $response->status());
            $this->assertSame(['status' => 'invalid'], $response->json());
            $event = \App\Models\PaymentWebhookEvent::sole();
            $this->assertSame('failed', $event->status);
            $this->assertNotSame('review_required', $event->status);
            $this->assertSame('pending', $order->refresh()->payment_status);
            $this->assertSame(0, PaymentTransaction::count());
            $this->assertSame($before, $this->financialSnapshot($order, $preference, $product));
        });
    }

    public static function pendingStaleLinks(): array
    {
        return [['superseded'], ['missing_current_pointer'], ['gateway_mismatch'], ['reference_mismatch']];
    }

    private function withInventoryFixture(\Closure $callback): void
    {
        $this->withRemoteLink(function ($transport, $order, $preference) use ($callback) {
            $product = Product::create(['name' => 'Synthetic manual review item', 'slug' => 'manual-review-'.bin2hex(random_bytes(12)),
                'sku' => 'MR-'.strtoupper(bin2hex(random_bytes(6))), 'price' => '10.25', 'is_active' => true]);
            $inventory = app(InventoryService::class);
            $warehouse = $inventory->defaultWarehouse();
            $inventory->initializeProduct($product, 5, $warehouse);
            $item = $order->items()->create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id,
                'quantity' => 1, 'price' => '10.25', 'subtotal' => '10.25']);
            $inventory->reserveForOrder($item, now()->addMinutes(30));
            $callback($transport, $order, $preference, $product);
        });
    }

    private function approveFirstPayment(object $transport, object $order, PaymentPreference $preference): void
    {
        $evidence = (new MercadoPagoGateway)->verifyPayment((string) $transport->paymentId);
        app(PaymentProcessingService::class)->process($preference, $evidence);
        $this->assertSame('approved', $order->refresh()->payment_status);
        $this->assertSame(1, PaymentTransaction::where('order_id', $order->id)->where('status', PaymentTransaction::APPROVED)->count());
    }

    private function body(string $paymentId): array
    {
        return ['id' => 'synthetic-event-'.bin2hex(random_bytes(12)), 'type' => 'payment',
            'action' => 'payment.updated', 'data' => ['id' => $paymentId]];
    }

    private function signedHeaders(string $paymentId): array
    {
        $requestId = 'synthetic-request-'.bin2hex(random_bytes(12));
        $timestamp = (string) time();

        return ['x-request-id' => $requestId, 'x-signature' => 'ts='.$timestamp.',v1='.hash_hmac('sha256',
            'id:'.$paymentId.';request-id:'.$requestId.';ts:'.$timestamp.';', $this->secret)];
    }

    private function financialSnapshot(object $order, PaymentPreference $preference, Product $product): string
    {
        $rows = [$order->refresh()->getRawOriginal(), $preference->refresh()->getRawOriginal(),
            DB::table('payment_transactions')->where('order_id', $order->id)->orderBy('id')->get()->all(),
            DB::table('inventory_reservations')->where('order_id', $order->id)->orderBy('id')->get()->all(),
            DB::table('warehouse_inventories')->where('product_id', $product->id)->orderBy('id')->get()->all(),
            DB::table('products')->where('id', $product->id)->first(),
            DB::table('inventory_movements')->where('product_id', $product->id)->orderBy('id')->get()->all(),
            DB::table('order_fulfillment_histories')->where('order_id', $order->id)->orderBy('id')->get()->all()];

        return hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
    }
}
