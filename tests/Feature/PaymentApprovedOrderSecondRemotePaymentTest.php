<?php

namespace Tests\Feature;

use App\Exceptions\PaymentFinancialReviewException;
use App\Models\InventoryMovement;
use App\Models\InventoryReservation;
use App\Models\PaymentPreference;
use App\Models\PaymentTransaction;
use App\Models\PaymentWebhookEvent;
use App\Models\Product;
use App\Models\WarehouseInventory;
use App\Services\InventoryService;
use App\Services\OrderPaymentService;
use App\Services\OrderStateService;
use App\Services\PaymentGateway\MercadoPagoGateway;
use App\Services\PaymentGateway\PaymentGatewayInterface;
use App\Services\PaymentProcessingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\SyntheticMercadoPagoLink;
use Tests\TestCase;

class PaymentApprovedOrderSecondRemotePaymentTest extends TestCase
{
    use RefreshDatabase;
    use SyntheticMercadoPagoLink;

    private string $secret;

    protected function setUp(): void
    {
        parent::setUp();
        $this->secret = 'SYNTHETIC_'.bin2hex(random_bytes(16));
        $token = 'SYNTHETIC_'.bin2hex(random_bytes(16));
        config(['payment.testing_legacy_webhook' => false, 'payment.mercadopago.access_token' => $token,
            'payment.mercadopago.webhook_secret' => $this->secret, 'payment.mock.enabled' => false]);
        // Force the factory to use a synthetic adapter credential, never a persisted provider token.
        $this->app->instance(PaymentGatewayInterface::class, new MercadoPagoGateway($token));
    }

    public function test_same_approved_remote_payment_is_idempotent_only_for_its_coherent_financial_identity(): void
    {
        $this->withApprovedInventoryFixture(function ($transport, $order, $preference, $product) {
            $gateway = new MercadoPagoGateway('SYNTHETIC_'.bin2hex(random_bytes(12)));
            $evidence = $gateway->verifyPayment((string) $transport->paymentId);
            app(PaymentProcessingService::class)->process($preference, $evidence);
            $before = $this->snapshot($order, $preference, $product, (string) $transport->paymentId);

            app(PaymentProcessingService::class)->process($preference, $evidence);

            $this->assertTrue(hash_equals($before, $this->snapshot($order, $preference, $product, (string) $transport->paymentId)), 'A coherent replay must not write financial, stock, event or history rows.');
            $this->assertSame(1, PaymentTransaction::where('provider_payment_id', (string) $transport->paymentId)->count());
            $this->assertSame('approved', $order->refresh()->payment_status);
        });
    }

    public function test_second_approved_remote_payment_is_rejected_for_review_without_financial_or_stock_writes(): void
    {
        $this->withApprovedInventoryFixture(function ($transport, $order, $preference, $product) {
            $this->approveFirstPayment($transport, $order, $preference);
            $secondId = $this->changeTransportToSecondApprovedPayment($transport);
            $evidence = (new MercadoPagoGateway('SYNTHETIC_'.bin2hex(random_bytes(12))))->verifyPayment($secondId);
            $before = $this->snapshot($order, $preference, $product, $secondId);
            $writes = new \ArrayObject;
            DB::listen(static function ($query) use ($writes) {
                if (preg_match('/^\s*(insert|update|delete|replace)\b/i', $query->sql)) {
                    $writes->append(true);
                }
            });

            $exception = $this->captureReview(fn () => app(PaymentProcessingService::class)->process($preference, $evidence));

            $this->assertSame(PaymentFinancialReviewException::CATEGORY, $exception->category);
            $this->assertCount(0, $writes);
            $this->assertTrue(hash_equals($before, $this->snapshot($order, $preference, $product, $secondId)), 'A second capture must not alter the approved payment or inventory.');
            $this->assertSame(1, PaymentTransaction::where('order_id', $order->id)->where('status', PaymentTransaction::APPROVED)->count());
        });
    }

    public function test_approved_order_without_approved_financial_transaction_requires_review(): void
    {
        $this->withApprovedInventoryFixture(function ($transport, $order, $preference, $product) {
            app(OrderStateService::class)->applyPaymentStatus($order, ['id' => 'synthetic-order-state', 'status' => 'approved']);
            $evidence = (new MercadoPagoGateway('SYNTHETIC_'.bin2hex(random_bytes(12))))->verifyPayment((string) $transport->paymentId);
            $before = $this->snapshot($order, $preference, $product, (string) $transport->paymentId);

            $exception = $this->captureReview(fn () => app(PaymentProcessingService::class)->process($preference, $evidence));

            $this->assertSame(PaymentFinancialReviewException::CATEGORY, $exception->category);
            $this->assertTrue(hash_equals($before, $this->snapshot($order, $preference, $product, (string) $transport->paymentId)), 'An approved order with no matching ledger row must remain untouched.');
            $this->assertDatabaseCount('payment_transactions', 0);
        });
    }

    /** @dataProvider incompatibleExistingStatuses */
    public function test_approved_order_with_pending_or_failed_same_payment_requires_review(string $status): void
    {
        $this->withApprovedInventoryFixture(function ($transport, $order, $preference, $product) use ($status) {
            $paymentId = (string) $transport->paymentId;
            DB::transaction(fn () => app(OrderPaymentService::class)->recordCardAttempt($order, $paymentId, $status, [
                'gateway' => 'mercadopago', 'payment_preference_id' => $preference->id,
                'provider_payment_id' => $paymentId, 'provider_status' => 'pending', 'verified_at' => now(),
            ], null, 'PEN'), 3);
            app(OrderStateService::class)->applyPaymentStatus($order, ['id' => 'synthetic-order-state', 'status' => 'approved']);
            $transport->payment['status'] = 'approved';
            $transport->merchant['payments'][0]['id'] = $transport->paymentId;
            $evidence = (new MercadoPagoGateway('SYNTHETIC_'.bin2hex(random_bytes(12))))->verifyPayment($paymentId);
            $before = $this->snapshot($order, $preference, $product, $paymentId);

            $exception = $this->captureReview(fn () => app(PaymentProcessingService::class)->process($preference, $evidence));

            $this->assertSame(PaymentFinancialReviewException::CATEGORY, $exception->category);
            $this->assertTrue(hash_equals($before, $this->snapshot($order, $preference, $product, $paymentId)), 'A pending or failed financial identity must not be silently upgraded beside an approved order.');
            $this->assertSame($status, PaymentTransaction::sole()->status);
        });
    }

    public static function incompatibleExistingStatuses(): array
    {
        return [[PaymentTransaction::PENDING], [PaymentTransaction::FAILED]];
    }

    /** @dataProvider incoherentReplayEvidence */
    public function test_approved_order_rejects_incoherent_payment_identity_for_review(string $field): void
    {
        $this->withApprovedInventoryFixture(function ($transport, $order, $preference, $product) use ($field) {
            $this->approveFirstPayment($transport, $order, $preference);
            $paymentId = (string) $transport->paymentId;
            $evidence = (new MercadoPagoGateway('SYNTHETIC_'.bin2hex(random_bytes(12))))->verifyPayment($paymentId);
            match ($field) {
                'amount' => $evidence['transaction_amount'] = 9.00,
                'currency' => $evidence['currency_id'] = 'USD',
                'reference' => $evidence['external_reference'] = 'synthetic-'.bin2hex(random_bytes(12)),
                'preference' => $evidence['preference_id'] = 'synthetic-'.bin2hex(random_bytes(12)),
            };
            $before = $this->snapshot($order, $preference, $product, $paymentId);

            $exception = $this->captureReview(fn () => app(PaymentProcessingService::class)->process($preference, $evidence));

            $this->assertSame(PaymentFinancialReviewException::CATEGORY, $exception->category);
            $this->assertTrue(hash_equals($before, $this->snapshot($order, $preference, $product, $paymentId)), 'Incoherent approved-payment evidence must not change financial or inventory state.');
        });
    }

    public static function incoherentReplayEvidence(): array
    {
        return [['amount'], ['currency'], ['reference'], ['preference']];
    }

    public function test_second_payment_webhook_is_terminally_reviewable_and_repeat_is_read_only(): void
    {
        $this->withApprovedInventoryFixture(function ($transport, $order, $preference, $product) {
            $this->approveFirstPayment($transport, $order, $preference);
            $secondId = $this->changeTransportToSecondApprovedPayment($transport);
            $token = 'SYNTHETIC_'.bin2hex(random_bytes(12));
            config(['payment.mercadopago.access_token' => $token]);
            $gateway = new MercadoPagoGateway($token);
            $this->app->instance(PaymentGatewayInterface::class, $gateway);
            $notificationId = 'synthetic-review-event-'.bin2hex(random_bytes(10));
            $requestId = 'synthetic-review-request-'.bin2hex(random_bytes(10));
            $body = ['id' => $notificationId, 'type' => 'payment', 'action' => 'payment.updated', 'data' => ['id' => $secondId]];
            $headers = $this->signedHeaders($secondId, $requestId);
            $beforeCalls = count($transport->calls);
            $beforeRows = $this->snapshot($order, $preference, $product, $secondId);

            $first = $this->postJson('/api/v1/payment/webhook', $body, $headers);
            $this->assertSame(202, $first->status());
            $this->assertSame(['status' => 'review_required', 'code' => PaymentWebhookEvent::FINANCIAL_REVIEW_REQUIRED], $first->json());
            $event = PaymentWebhookEvent::sole();
            $this->assertSame($secondId, $event->provider_payment_id);
            $this->assertSame(PaymentWebhookEvent::REVIEW_REQUIRED, $event->status);
            $this->assertSame(PaymentWebhookEvent::FINANCIAL_REVIEW_REQUIRED, $event->failure_reason);
            $this->assertSame($beforeCalls + 2, count($transport->calls));
            $afterFirst = $this->snapshot($order, $preference, $product, $secondId);
            $this->assertTrue(! hash_equals($beforeRows, $afterFirst), 'Only the safe review event should be added.');
            $this->assertSame(1, PaymentWebhookEvent::where('provider_payment_id', $secondId)->count());
            $this->assertSame(0, PaymentWebhookEvent::where('provider_payment_id', $secondId)->where('status', PaymentWebhookEvent::PROCESSED)->count());

            $repeat = $this->postJson('/api/v1/payment/webhook', $body, $headers);
            $this->assertSame(202, $repeat->status());
            $this->assertSame(['status' => 'review_required', 'code' => PaymentWebhookEvent::FINANCIAL_REVIEW_REQUIRED], $repeat->json());
            $this->assertSame($afterFirst, $this->snapshot($order, $preference, $product, $secondId));
            $this->assertSame($beforeCalls + 2, count($transport->calls), 'Terminal review replay must not query the provider again.');
            $this->assertSame(1, PaymentWebhookEvent::where('provider_payment_id', $secondId)->count());
            $this->assertSame(0, PaymentWebhookEvent::where('provider_payment_id', $secondId)->where('status', PaymentWebhookEvent::PROCESSED)->count());
        });
    }

    private function withApprovedInventoryFixture(\Closure $callback): void
    {
        $this->withRemoteLink(function ($transport, $order, $preference) use ($callback) {
            $product = Product::create([
                'name' => 'Synthetic review item', 'slug' => 'review-'.bin2hex(random_bytes(12)),
                'sku' => 'REV-'.strtoupper(bin2hex(random_bytes(6))), 'price' => '10.25', 'is_active' => true,
            ]);
            $inventory = app(InventoryService::class);
            $warehouse = $inventory->defaultWarehouse();
            $inventory->initializeProduct($product, 5, $warehouse);
            $item = $order->items()->create([
                'product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'quantity' => 1,
                'price' => '10.25', 'subtotal' => '10.25',
            ]);
            $inventory->reserveForOrder($item, now()->addMinutes(30));
            $callback($transport, $order, $preference, $product, $item);
        });
    }

    private function approveFirstPayment(object $transport, object $order, PaymentPreference $preference): void
    {
        $gateway = new MercadoPagoGateway('SYNTHETIC_'.bin2hex(random_bytes(12)));
        app(PaymentProcessingService::class)->process($preference, $gateway->verifyPayment((string) $transport->paymentId));
        $this->assertSame('approved', $order->refresh()->payment_status);
        $this->assertSame(1, PaymentTransaction::where('order_id', $order->id)->where('status', PaymentTransaction::APPROVED)->count());
    }

    private function changeTransportToSecondApprovedPayment(object $transport): string
    {
        $paymentId = random_int(110000000, 199999999);
        $transport->paymentId = $paymentId;
        $transport->payment['id'] = $paymentId;
        $transport->payment['status'] = 'approved';
        $transport->merchant['payments'] = [[
            'id' => $paymentId, 'transaction_amount' => 10.25, 'currency_id' => 'PEN',
        ]];
        return (string) $paymentId;
    }

    private function captureReview(\Closure $operation): PaymentFinancialReviewException
    {
        try {
            $operation();
        } catch (PaymentFinancialReviewException $exception) {
            return $exception;
        }
        $this->fail('Financially inconsistent payment must require review.');
    }

    private function signedHeaders(string $paymentId, string $requestId): array
    {
        $timestamp = (string) time();
        return [
            'x-request-id' => $requestId,
            'x-signature' => 'ts='.$timestamp.',v1='.hash_hmac('sha256', 'id:'.$paymentId.';request-id:'.$requestId.';ts:'.$timestamp.';', $this->secret),
        ];
    }

    private function snapshot(object $order, PaymentPreference $preference, Product $product, string $paymentId): string
    {
        $rows = [
            $order->refresh()->getRawOriginal(),
            $preference->refresh()->getRawOriginal(),
            DB::table('payment_transactions')->where('order_id', $order->id)->orderBy('id')->get()->all(),
            DB::table('inventory_reservations')->where('order_id', $order->id)->orderBy('id')->get()->all(),
            DB::table('warehouse_inventories')->where('product_id', $product->id)->orderBy('id')->get()->all(),
            DB::table('products')->where('id', $product->id)->first(),
            DB::table('inventory_movements')->where('product_id', $product->id)->orderBy('id')->get()->all(),
            DB::table('order_fulfillment_histories')->where('order_id', $order->id)->orderBy('id')->get()->all(),
            DB::table('payment_webhook_events')->where('provider_payment_id', $paymentId)->orderBy('id')->get()->all(),
        ];
        return hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
    }
}
