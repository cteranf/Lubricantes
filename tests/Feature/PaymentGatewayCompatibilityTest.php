<?php

namespace Tests\Feature;

use App\Exceptions\PaymentFinancialReviewException;
use App\Exceptions\PaymentLinkEvidenceException as LinkFailure;
use App\Models\PaymentSetting;
use App\Models\PaymentTransaction;
use App\Services\PaymentGateway\MercadoPagoGateway;
use App\Services\PaymentGateway\PaymentGatewayFactory;
use App\Services\PaymentProcessingService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use MercadoPago\MercadoPagoConfig;
use MercadoPago\Net\MPHttpClient;
use MercadoPago\Net\MPRequest;
use MercadoPago\Net\MPResponse;
use Tests\Feature\Concerns\SyntheticMercadoPagoLink;
use Tests\TestCase;

class PaymentGatewayCompatibilityTest extends TestCase
{
    use RefreshDatabase;
    use SyntheticMercadoPagoLink;

    private mixed $originalTransport;

    private ?string $originalToken;

    private object $guard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalTransport = (new \ReflectionProperty(MercadoPagoConfig::class, 'http_client'))->getValue();
        $this->originalToken = MercadoPagoConfig::getAccessToken();
        config(['payment.mercadopago.access_token' => null]);
        $this->guard = new class implements MPHttpClient
        {
            public int $calls = 0;

            public function send(MPRequest $request): MPResponse
            {
                $this->calls++;
                throw new \LogicException('Unexpected remote request in constructor certification.');
            }
        };
        MercadoPagoConfig::setHttpClient($this->guard);
    }

    protected function tearDown(): void
    {
        (new \ReflectionProperty(MercadoPagoConfig::class, 'http_client'))->setValue(null, $this->originalTransport);
        MercadoPagoConfig::setAccessToken($this->originalToken);
        parent::tearDown();
    }

    public function test_factory_uses_valid_persisted_synthetic_credential_without_remote_requests(): void
    {
        $token = 'SYNTHETIC_'.bin2hex(random_bytes(16));
        PaymentSetting::current()->update(['mercadopago_access_token' => $token]);
        $this->assertInstanceOf(MercadoPagoGateway::class, PaymentGatewayFactory::create('mercadopago'));
        $this->assertTrue(MercadoPagoConfig::getAccessToken() === $token, 'Factory must supply the configured credential.');
        $this->assertSame(0, $this->guard->calls);
    }

    /** @dataProvider absentOrInvalidCredentials */
    public function test_factory_and_constructor_fail_safely_for_absent_or_invalid_credentials(?string $token, bool $factory): void
    {
        if ($factory) {
            PaymentSetting::current()->update(['mercadopago_access_token' => $token]);
        }
        $rejected = false;
        try {
            $factory ? PaymentGatewayFactory::create('mercadopago') : new MercadoPagoGateway($token);
        } catch (\RuntimeException) {
            $rejected = true;
        }
        $this->assertTrue($rejected, 'Invalid credentials must fail before any remote request.');
        $this->assertSame(0, $this->guard->calls);
    }

    public static function absentOrInvalidCredentials(): array
    {
        return [[null, false], ['', false], ['   ', false], [null, true], ['', true], [" \t ", true], [' SYNTHETIC', false], ['SYNTHETIC ', false], [' SYNTHETIC', true], ['SYNTHETIC ', true]];
    }

    /** @dataProvider fallbackCredentials */
    public function test_null_or_empty_constructor_uses_valid_configuration_without_altering_credential(?string $input): void
    {
        $token = 'SYNTHETIC_'.bin2hex(random_bytes(16));
        config(['payment.mercadopago.access_token' => $token]);
        $this->assertInstanceOf(MercadoPagoGateway::class, new MercadoPagoGateway($input));
        $this->assertTrue(MercadoPagoConfig::getAccessToken() === $token, 'Credential must remain byte-for-byte identical.');
        $this->assertSame(0, $this->guard->calls);
    }

    public static function fallbackCredentials(): array
    {
        return [[null], ['']];
    }

    public function test_invalid_explicit_credential_does_not_fall_back_to_valid_configuration(): void
    {
        config(['payment.mercadopago.access_token' => 'SYNTHETIC_'.bin2hex(random_bytes(16))]);
        $this->expectException(\RuntimeException::class);
        new MercadoPagoGateway(' SYNTHETIC ');
    }

    public function test_processing_current_locator_and_repetition_preserve_financial_identity(): void
    {
        $this->freezeTime();
        $this->withRemoteLink(function ($transport, $order, $preference) {
            $transport->payment['status'] = 'rejected';
            $evidence = (new MercadoPagoGateway)->verifyPayment((string) $transport->paymentId);
            $processor = app(PaymentProcessingService::class);
            $first = $processor->process($preference, $evidence);
            $this->assertSame('rejected', $first->payment_status);
            $transaction = PaymentTransaction::where('order_id', $order->id)->sole();
            $this->assertSame(PaymentTransaction::FAILED, $transaction->status);
            $this->assertTrue((int) $transaction->payment_preference_id === (int) $preference->id);
            $before = $transaction->getRawOriginal();
            $processor->process($preference, $evidence);
            $this->assertDatabaseCount('payment_transactions', 1);
            $this->assertTrue($before === $transaction->refresh()->getRawOriginal(), 'Repeated evidence must preserve the financial entry.');
        });
    }

    public function test_processing_stale_locator_rejects_without_financial_writes(): void
    {
        $this->withRemoteLink(function ($transport, $order, $preference) {
            $evidence = (new MercadoPagoGateway)->verifyPayment((string) $transport->paymentId);
            $order->update(['current_payment_preference_id' => null]);
            $rejected = false;
            try {
                app(PaymentProcessingService::class)->process($preference, $evidence);
            } catch (ValidationException $exception) {
                $rejected = ($exception->errors()['payment'] ?? []) === [LinkFailure::STALE_LOCAL_LINK];
            }
            $this->assertTrue($rejected, 'Stale locator must be rejected.');
            $this->assertDatabaseCount('payment_transactions', 0);
            $this->assertSame('pending', $order->refresh()->payment_status);
        });
    }

    public function test_sql_failure_propagates_and_rolls_back_financial_writes(): void
    {
        $this->withRemoteLink(function ($transport, $order, $preference) {
            $transport->payment['status'] = 'rejected';
            $evidence = (new MercadoPagoGateway)->verifyPayment((string) $transport->paymentId);
            $sqlite = DB::connection()->getDriverName() === 'sqlite';
            if ($sqlite) {
                DB::unprepared("CREATE TEMP TRIGGER synthetic_payment_failure BEFORE INSERT ON payment_transactions BEGIN SELECT RAISE(ABORT, 'synthetic failure'); END");
            } else {
                // This unrelated synthetic row collides only on the existing
                // global UNIQUE key. Provider/order lookups cannot reuse it.
                PaymentTransaction::create([
                    'order_id' => $order->id, 'gateway' => 'synthetic_fixture',
                    'payment_method' => 'card', 'transaction_type' => PaymentTransaction::PAYMENT,
                    'status' => PaymentTransaction::FAILED, 'amount' => '10.25', 'currency' => 'PEN',
                    'idempotency_key' => 'gateway-mercadopago-payment-'.$transport->paymentId,
                    'external_reference' => 'synthetic-'.bin2hex(random_bytes(16)),
                ]);
            }
            $before = $order->refresh()->getRawOriginal();
            $beforePreference = $preference->refresh()->getRawOriginal();
            $financialSnapshot = static fn () => hash('sha256', json_encode(DB::table('payment_transactions')->orderBy('id')->get()->all()));
            $beforeFinancial = $financialSnapshot();
            $beforeCount = PaymentTransaction::count();
            $beforeLevel = DB::transactionLevel();
            $canary = new \ArrayObject(['executed' => false, 'inside_transaction' => false]);
            PaymentTransaction::creating(static function (PaymentTransaction $candidate) use ($order, $transport, $beforeLevel, $canary) {
                if ((int) $candidate->order_id === (int) $order->id
                    && $candidate->gateway === 'mercadopago'
                    && $candidate->provider_payment_id === (string) $transport->paymentId) {
                    $canary['inside_transaction'] = DB::transactionLevel() > $beforeLevel;
                    DB::table('orders')->where('id', $order->id)->update([
                        'shipping_info' => json_encode(['synthetic_rollback_marker' => true]),
                    ]);
                    $canary['executed'] = true;
                }
            });
            $propagated = false;
            $physicalConstraint = false;
            try {
                app(PaymentProcessingService::class)->process($preference, $evidence);
            } catch (QueryException $exception) {
                $propagated = true;
                $physicalConstraint = ($exception->errorInfo[0] ?? null) === '23000'
                    && (int) ($exception->errorInfo[1] ?? 0) === ($sqlite ? 19 : 1062);
            } finally {
                if ($sqlite) {
                    DB::unprepared('DROP TRIGGER synthetic_payment_failure');
                }
            }
            $this->assertTrue($propagated, 'Physical SQL failure must propagate.');
            $this->assertTrue($physicalConstraint, 'The expected physical integrity constraint must fail.');
            $this->assertTrue($canary['executed'] && $canary['inside_transaction'], 'A real preceding write must execute inside the processing transaction.');
            $this->assertTrue($before === $order->refresh()->getRawOriginal(), 'Failed processing must preserve the order.');
            $this->assertTrue($beforePreference === $preference->refresh()->getRawOriginal(), 'Failed processing must preserve the preference.');
            $this->assertTrue(hash_equals($beforeFinancial, $financialSnapshot()), 'Failed processing must preserve every pre-existing financial row.');
            $this->assertTrue(PaymentTransaction::count() === $beforeCount, 'Failed processing must create no financial rows.');
            $this->assertTrue(PaymentTransaction::where('gateway', 'mercadopago')->where('provider_payment_id', (string) $transport->paymentId)->count() === 0, 'The destination payment must have no financial entry.');
            $this->assertTrue(DB::transactionLevel() === $beforeLevel, 'Processing must restore the original transaction depth.');
            $this->assertTrue((int) DB::selectOne('SELECT 1 AS connection_usable')->connection_usable === 1, 'The database connection must remain usable after rollback.');
        });
    }

    public function test_processor_delegates_exactly_three_transaction_attempts(): void
    {
        $this->withRemoteLink(function ($transport, $order, $preference) {
            $transport->payment['status'] = 'under_review';
            $evidence = (new MercadoPagoGateway)->verifyPayment((string) $transport->paymentId);
            $connection = DB::connection();
            DB::shouldReceive('transaction')->once()->with(\Mockery::type(\Closure::class), 3)
                ->andReturnUsing(static fn ($callback, $attempts) => $connection->transaction($callback, $attempts));
            try {
                app(PaymentProcessingService::class)->process($preference, $evidence);
                $this->fail('Unsupported remote status must be routed to manual review.');
            } catch (PaymentFinancialReviewException $exception) {
                $this->assertSame(PaymentFinancialReviewException::UNSUPPORTED_REMOTE_STATUS, $exception->category);
            }
        });
    }
}
