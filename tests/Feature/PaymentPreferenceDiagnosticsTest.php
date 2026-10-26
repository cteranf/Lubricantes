<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\PaymentPreference;
use App\Models\PaymentSetting;
use App\Models\User;
use App\Services\PaymentGateway\MockPaymentGateway;
use App\Services\PaymentGateway\PaymentGatewayInterface;
use App\Services\PaymentPreferenceFailureDiagnostics;
use App\Services\PaymentPreferenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;
use MercadoPago\Exceptions\MPBadRequestException;
use MercadoPago\Net\MPResponse;
use Tests\TestCase;

class PaymentPreferenceDiagnosticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_uncertain_creation_logs_only_safe_evidence_and_http_retry_stays_blocked(): void
    {
        $order = $this->order();
        PaymentSetting::current()->update(['card_enabled' => true, 'card_gateway' => 'mercadopago']);
        Sanctum::actingAs($order->user);
        $gateway = $this->gateway(new \Exception('Operation timed out '.self::privateText(), 28));
        $logger = $this->logger();
        $original = Log::getFacadeRoot();
        try {
            Log::swap($logger);
            $this->postJson('/api/v1/payment/create', ['order_id' => $order->id])->assertStatus(409)
                ->assertExactJson(['code' => 'payment_reconciliation_required', 'message' => 'La preferencia no está disponible todavía; no se creó un segundo intento.']);
            $preference = PaymentPreference::where('order_id', $order->id)->sole();
            $before = $preference->getRawOriginal();
            $this->postJson('/api/v1/payment/create', ['order_id' => $order->id])->assertStatus(409);
            $this->assertTrue($before === $preference->fresh()->getRawOriginal(), 'Second attempt must preserve uncertain intent unchanged.');
            $this->assertSame(1, $gateway->calls);
            $this->assertSame(1, count($logger->records));
            $this->assertTrue($preference->state === 'orphaned' && $preference->error_code === 'remote_result_uncertain' && $preference->provider_preference_id === null && $preference->init_point === null && $preference->sandbox_init_point === null, 'Uncertain creation must preserve its existing classification.');
            [$event, $context] = $logger->records[0];
            $this->assertTrue($event === 'payment_preference_creation_failed' && $context === [
                'exception_class' => \Exception::class, 'exception_code' => 28,
                'http_status' => null, 'provider_error_code' => null,
                'message' => 'Provider request timed out; remote result uncertain.',
                'provider_request_id' => null, 'order_id' => $order->id,
                'payment_preference_id' => $preference->id, 'gateway' => 'mercadopago',
                'stage' => 'remote_creation',
            ], 'Only the safe diagnostic contract may be logged.');
            $this->assertSafeContext($context, [$preference->external_reference, $preference->idempotency_key]);
        } finally {
            Log::swap($original);
            app()->forgetInstance(PaymentGatewayInterface::class);
        }
    }

    public function test_classified_sdk_rejection_retains_failed_state_and_sanitized_response_evidence(): void
    {
        $order = $this->order();
        $response = new MPResponse(400, ['error' => 'invalid_items', 'message' => self::privateText(), 'request_id' => '12345678-1234-4234-8234-123456789abc', 'request' => ['authorization' => self::privateText()]]);
        $this->gateway(new MPBadRequestException('Api error. Check response for details', $response));
        $logger = $this->logger();
        $original = Log::getFacadeRoot();
        try {
            Log::swap($logger);
            $preference = app(PaymentPreferenceService::class)->createFor($order, [], 'mercadopago');
            $this->assertTrue($preference->state === 'failed' && $preference->error_code === 'provider_rejected', 'SDK rejection must retain its existing domain classification.');
            $context = $logger->records[0][1];
            $this->assertTrue($context['http_status'] === 400 && $context['provider_error_code'] === 'invalid_items' && $context['provider_request_id'] === '12345678-1234-4234-8234-123456789abc' && $context['exception_class'] === MPBadRequestException::class, 'Installed SDK response APIs must supply structured evidence.');
            $this->assertSame(1, count($logger->records));
            $this->assertTrue($logger->records[0][0] === 'payment_preference_creation_failed', 'Only the fixed diagnostic event may be logged.');
            $this->assertSafeContext($context, [$preference->external_reference, $preference->idempotency_key]);
        } finally {
            Log::swap($original);
            app()->forgetInstance(PaymentGatewayInterface::class);
        }
    }

    public function test_untrusted_codes_ids_messages_and_empty_sdk_bodies_are_not_logged(): void
    {
        $preference = new PaymentPreference(['order_id' => 1, 'gateway' => 'mercadopago']);
        foreach ([['error' => self::privateText(), 'request_id' => self::privateText(), 'message' => self::privateText()], null] as $body) {
            $exception = new MPBadRequestException(self::privateText(), new MPResponse(400, $body));
            $context = PaymentPreferenceFailureDiagnostics::context($preference, new \RuntimeException(self::privateText(), 0, $exception), 'remote_creation');
            $this->assertTrue($context['http_status'] === 400 && $context['provider_error_code'] === null && $context['provider_request_id'] === null, 'Untrusted or empty SDK evidence must be safely omitted.');
            $this->assertSafeContext($context);
        }
        $context = PaymentPreferenceFailureDiagnostics::context($preference, new \TypeError(self::privateText()), 'remote_creation');
        $this->assertTrue($context['message'] === 'Gateway or SDK type error; exception text withheld.', 'Local type failures must be identifiable without request text.');
        $this->assertSafeContext($context);
    }

    public function test_logging_failure_does_not_prevent_orphaning_or_retry_block(): void
    {
        $order = $this->order();
        $gateway = $this->gateway(new \RuntimeException(self::privateText()));
        $logger = $this->logger();
        $logger->fail = true;
        $original = Log::getFacadeRoot();
        try {
            Log::swap($logger);
            $service = app(PaymentPreferenceService::class);
            $preference = $service->createFor($order, [], 'mercadopago');
            $again = $service->createFor($order, [], 'mercadopago');
            $this->assertTrue($preference->state === 'orphaned' && $again->id === $preference->id && $gateway->calls === 1, 'Logging failure cannot change state or trigger a remote retry.');
        } finally {
            Log::swap($original);
            app()->forgetInstance(PaymentGatewayInterface::class);
        }
    }

    public function test_gateway_initialization_failure_records_its_distinct_stage(): void
    {
        $order = $this->order();
        app()->bind(PaymentGatewayInterface::class, function () {
            throw new \RuntimeException(self::privateText());
        });
        $logger = $this->logger();
        $original = Log::getFacadeRoot();
        try {
            Log::swap($logger);
            $preference = app(PaymentPreferenceService::class)->createFor($order, [], 'mercadopago');
            $this->assertTrue($preference->state === 'orphaned' && $logger->records[0][1]['stage'] === 'gateway_initialization', 'Factory errors must identify initialization without claiming a remote HTTP result.');
            $this->assertSame(1, count($logger->records));
            $this->assertTrue($logger->records[0][0] === 'payment_preference_creation_failed', 'Only the fixed diagnostic event may be logged.');
            $this->assertSafeContext($logger->records[0][1], [$preference->external_reference, $preference->idempotency_key]);
        } finally {
            Log::swap($original);
            app()->offsetUnset(PaymentGatewayInterface::class);
        }
    }

    public function test_state_transition_failure_still_propagates_unchanged(): void
    {
        $order = $this->order();
        $this->gateway(new \RuntimeException(self::privateText()));
        $failure = new \LogicException('Synthetic state transition failure');
        $service = new class($failure) extends PaymentPreferenceService
        {
            public function __construct(private \Throwable $failure) {}

            public function orphan(PaymentPreference $preference, string $reason): PaymentPreference
            {
                throw $this->failure;
            }
        };
        $original = Log::getFacadeRoot();
        $caught = null;
        try {
            Log::swap($this->logger());
            $service->createFor($order, [], 'mercadopago');
        } catch (\Throwable $exception) {
            $caught = $exception;
        } finally {
            Log::swap($original);
            app()->forgetInstance(PaymentGatewayInterface::class);
        }
        $this->assertTrue($caught === $failure, 'State transition errors must propagate without replacement.');
    }

    private function order(): Order
    {
        $this->assertTrue(config('database.default') === 'sqlite' && config('database.connections.sqlite.database') === ':memory:', 'Diagnostics tests require isolated SQLite memory.');

        return Order::create(['user_id' => User::factory()->create()->id, 'status' => 'pending', 'total' => 10, 'shipping_info' => [], 'payment_method' => 'card', 'payment_status' => 'pending', 'delivery_type' => 'delivery', 'tracking_status' => 'pending']);
    }

    private function gateway(\Throwable $failure): MockPaymentGateway
    {
        $gateway = new class($failure) extends MockPaymentGateway
        {
            public int $calls = 0;

            public function __construct(private \Throwable $failure) {}

            public function createPayment(array $orderData): array
            {
                $this->calls++;
                throw $this->failure;
            }
        };
        app()->instance(PaymentGatewayInterface::class, $gateway);

        return $gateway;
    }

    private function logger(): object
    {
        return new class
        {
            public array $records = [];

            public bool $fail = false;

            public function warning(string $event, array $context): void
            {
                if ($this->fail) {
                    throw new \RuntimeException('Synthetic logger unavailable');
                }
                $this->records[] = [$event, $context];
            }
        };
    }

    private function assertSafeContext(array $context, array $additionalPrivateValues = []): void
    {
        $keys = array_keys($context);
        $expectedKeys = ['exception_class', 'exception_code', 'http_status', 'provider_error_code', 'message', 'provider_request_id', 'order_id', 'payment_preference_id', 'gateway', 'stage'];
        sort($keys);
        sort($expectedKeys);
        $this->assertTrue($keys === $expectedKeys, 'Diagnostic context must contain exactly the allowed keys.');
        foreach ($context as $key => $value) {
            $validType = match ($key) {
                'exception_code', 'http_status' => $value === null || is_int($value),
                'order_id', 'payment_preference_id' => is_int($value),
                'provider_error_code', 'provider_request_id' => $value === null || is_string($value),
                default => is_string($value),
            };
            $this->assertTrue($validType, 'Diagnostic values must have their exact scalar or nullable types.');
            if (! is_string($value)) {
                continue;
            }
            foreach (array_merge(self::privateSentinels(), $additionalPrivateValues) as $private) {
                // Check each original and a substantial fragment directly, before JSON escaping.
                $fragment = substr($private, 0, max(8, intdiv(strlen($private), 2)));
                $this->assertTrue(stripos($value, $private) === false && stripos($value, $fragment) === false, 'Private values and recognizable fragments must never enter diagnostic context.');
            }
        }
        $this->assertTrue(in_array($context['message'], [
            'Provider returned an HTTP error; response text withheld.',
            'Gateway or SDK type error; exception text withheld.',
            'TLS certificate validation failed.',
            'Provider hostname resolution failed.',
            'Provider request timed out; remote result uncertain.',
            'Provider connection timed out; remote result uncertain.',
            'Provider connection failed; remote result uncertain.',
            'Unclassified gateway failure; exception and request text withheld.',
        ], true), 'Diagnostic messages must be fixed text, never serialized responses or exceptions.');
        $this->assertTrue(in_array($context['stage'], ['gateway_initialization', 'remote_creation'], true), 'Diagnostic stage must match the contractual allowlist.');
    }

    private static function privateSentinels(): array
    {
        return [
            'SYNTHETIC_PRIVATE_ACCESS_TOKEN_VALUE',
            'Authorization: Bearer SYNTHETIC_PRIVATE_AUTHORIZATION_VALUE',
            'idempotency_key=SYNTHETIC_PRIVATE_IDEMPOTENCY_VALUE',
            'external_reference=SYNTHETIC_PRIVATE_REFERENCE_VALUE',
            'init_point=https://invalid.example/SYNTHETIC_PRIVATE_CHECKOUT_VALUE',
            'sandbox_init_point=https://invalid.example/SYNTHETIC_PRIVATE_SANDBOX_VALUE',
            'SYNTHETIC_PRIVATE_BUYER_NAME',
            'SYNTHETIC_PRIVATE_EMAIL@example.invalid',
            'SYNTHETIC_PRIVATE_DOCUMENT_NUMBER',
            'SYNTHETIC_PRIVATE_PHONE_NUMBER',
            'SYNTHETIC_PRIVATE_STREET_ADDRESS',
            'SYNTHETIC_PRIVATE_SDK_MESSAGE',
            'SYNTHETIC_PRIVATE_RESPONSE_CONTENT',
            'SYNTHETIC_PRIVATE_PAYLOAD_CONTENT',
        ];
    }

    private static function privateText(): string
    {
        return implode(' ', self::privateSentinels())."\r\n";
    }
}
