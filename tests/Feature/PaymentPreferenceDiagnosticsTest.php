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
        $failure = new \Exception('Operation timed out '.self::privateText(), 28);
        $gateway = $this->gateway($failure);
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
                'origin_component' => 'unknown', 'origin_file' => 'unknown',
                'origin_line' => $failure->getLine(), 'error_category' => 'network_runtime',
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

    /** @dataProvider runtimeOrigins */
    public function test_runtime_origins_are_relative_or_unknown_and_categories_are_fixed(string $path, bool $windows, string $component, string $file, string $prefix, int $severity, string $category): void
    {
        if (str_starts_with($path, '@root/')) {
            $path = str_replace('\\', '/', base_path(substr($path, 6)));
        }
        if ($windows) {
            $path = str_replace('/', '\\', $path);
        }
        $originalIgnoreArgs = ini_get('zend.exception_ignore_args');
        try {
            ini_set('zend.exception_ignore_args', '0');
            $exception = $this->runtimeFailure(self::privateText(), $prefix, $severity, $path, 127);
            $context = PaymentPreferenceFailureDiagnostics::context(new PaymentPreference(['order_id' => 1, 'gateway' => 'mercadopago']), $exception, 'remote_creation');
            $this->assertTrue($context['origin_component'] === $component && $context['origin_file'] === $file && $context['origin_line'] === 127 && $context['error_category'] === $category, 'Runtime origin and category must use only their safe contractual representations.');
            $this->assertSafeContext($context);
        } finally {
            ini_set('zend.exception_ignore_args', $originalIgnoreArgs);
        }
    }

    public static function runtimeOrigins(): array
    {
        return [
            'app unix separators' => ['@root/app/Services/PaymentPreferenceService.php', false, 'app', 'app/Services/PaymentPreferenceService.php', 'Undefined array key ', E_WARNING, 'undefined_array_key'],
            'app windows separators' => ['@root/app/Services/PaymentPreferenceService.php', true, 'app', 'app/Services/PaymentPreferenceService.php', 'Undefined property: ', E_WARNING, 'undefined_property'],
            'sdk unix separators' => ['@root/vendor/mercadopago/dx-php/src/MercadoPago/Serialization/Mapper.php', false, 'mercadopago_sdk', 'vendor/mercadopago/dx-php/src/MercadoPago/Serialization/Mapper.php', '', E_DEPRECATED, 'deprecated_runtime'],
            'sdk windows separators' => ['@root/vendor/mercadopago/dx-php/src/MercadoPago/Client/Preference/PreferenceClient.php', true, 'mercadopago_sdk', 'vendor/mercadopago/dx-php/src/MercadoPago/Client/Preference/PreferenceClient.php', 'Trying to access array offset on value of type ', E_WARNING, 'invalid_argument_shape'],
            'other vendor' => ['@root/vendor/other/package/Runtime.php', false, 'unknown', 'unknown', 'fopen(): Permission denied ', E_WARNING, 'filesystem_or_permission'],
            'external linux' => ['/srv/private/SYNTHETIC_PRIVATE_STREET_ADDRESS/Runtime.php', false, 'unknown', 'unknown', 'cURL error 28: ', E_WARNING, 'network_runtime'],
            'external windows' => ['C:\\private\\SYNTHETIC_PRIVATE_BUYER_NAME\\Runtime.php', true, 'unknown', 'unknown', '', E_WARNING, 'unknown_runtime_error'],
            'traversal rejected' => ['@root/app/../private/Runtime.php', false, 'unknown', 'unknown', '', E_WARNING, 'unknown_runtime_error'],
            'unsafe filename rejected' => ['@root/app/Services/buyer@example.invalid.php', false, 'unknown', 'unknown', '', E_WARNING, 'unknown_runtime_error'],
            'similarly named sdk rejected' => ['@root/vendor/mercadopago/dx-php-other/Runtime.php', false, 'unknown', 'unknown', '', E_WARNING, 'unknown_runtime_error'],
        ];
    }

    public function test_invalid_runtime_lines_are_omitted_and_valid_bounds_are_preserved(): void
    {
        foreach ([0, -1, 1000001, PHP_INT_MAX, 1, 1000000] as $line) {
            $exception = $this->runtimeFailure(self::privateText(), 'Undefined array key ', E_WARNING, str_replace('\\', '/', base_path('app/Services/PaymentPreferenceService.php')), $line);
            $context = PaymentPreferenceFailureDiagnostics::context(new PaymentPreference(['order_id' => 1, 'gateway' => 'mercadopago']), $exception, 'remote_creation');
            $this->assertTrue($context['origin_line'] === ($line > 0 && $line <= 1000000 ? $line : null), 'Runtime lines must be positive and bounded or omitted.');
            $this->assertSafeContext($context);
        }
    }

    public function test_error_exception_origin_is_logged_without_changing_uncertain_state(): void
    {
        $order = $this->order();
        $file = 'vendor/mercadopago/dx-php/src/MercadoPago/Serialization/Mapper.php';
        $gateway = $this->gateway($this->runtimeFailure(self::privateText(), 'Undefined array key ', E_WARNING, str_replace('\\', '/', base_path($file)), 42));
        $logger = $this->logger();
        $original = Log::getFacadeRoot();
        try {
            Log::swap($logger);
            $service = app(PaymentPreferenceService::class);
            $preference = $service->createFor($order, [], 'mercadopago');
            $before = $preference->getRawOriginal();
            $again = $service->createFor($order, [], 'mercadopago');
            $this->assertTrue($preference->state === 'orphaned' && $preference->error_code === 'remote_result_uncertain' && $before === $again->getRawOriginal() && $gateway->calls === 1, 'Runtime diagnostics must preserve uncertain intent and block remote retry.');
            $this->assertTrue(count($logger->records) === 1 && $logger->records[0][0] === 'payment_preference_creation_failed', 'Only one fixed diagnostic event may be recorded.');
            $context = $logger->records[0][1];
            $this->assertTrue($context['exception_class'] === \ErrorException::class && $context['exception_code'] === 0 && $context['origin_component'] === 'mercadopago_sdk' && $context['origin_file'] === $file && $context['origin_line'] === 42 && $context['error_category'] === 'undefined_array_key' && $context['stage'] === 'remote_creation', 'ErrorException origin must be reported through the exact sanitized contract.');
            $this->assertSafeContext($context, [$preference->idempotency_key, $preference->external_reference]);
        } finally {
            Log::swap($original);
            app()->forgetInstance(PaymentGatewayInterface::class);
        }
    }

    /** @dataProvider physicalOriginPolicies */
    public function test_origin_policy_requires_canonical_files_and_rejects_links_portably(bool $windows, string $scenario): void
    {
        // A deterministic filesystem abstraction exercises Windows and POSIX on either host.
        // Native resolution of actual app/SDK files is covered by runtimeOrigins above.
        $base = $windows ? 'C:/project' : '/srv/project';
        $app = $base.'/app';
        $sdk = $base.'/vendor/mercadopago/dx-php';
        $appFile = $app.'/Services/Origin.php';
        $sdkFile = $sdk.'/src/Origin.php';
        $canonical = array_fill_keys([$app, $app.'/Services', $appFile, $sdk, $sdk.'/src', $sdkFile], true);
        $canonical = array_combine(array_keys($canonical), array_keys($canonical));
        $files = [$appFile => true, $sdkFile => true];
        $links = [];
        $path = $appFile;
        $expected = ['origin_component' => 'unknown', 'origin_file' => 'unknown'];
        switch ($scenario) {
            case 'valid app': $expected = ['origin_component' => 'app', 'origin_file' => 'app/Services/Origin.php'];
                break;
            case 'valid sdk': $path = $sdkFile;
                $expected = ['origin_component' => 'mercadopago_sdk', 'origin_file' => 'vendor/mercadopago/dx-php/src/Origin.php'];
                break;
            case 'mixed windows first': $path = str_replace('/', '\\', $app).'/Services/Origin.php';
                break;
            case 'mixed posix first': $path = $app.'/Services\\Origin.php';
                break;
            case 'similar app prefix': $path = $base.'/app-other/Services/Origin.php';
                $canonical[$path] = $path;
                $files[$path] = true;
                break;
            case 'foreign vendor': $path = $base.'/vendor/other/Origin.php';
                $canonical[$path] = $path;
                $files[$path] = true;
                break;
            case 'similar sdk prefix': $path = $sdk.'-other/src/Origin.php';
                $canonical[$path] = $path;
                $files[$path] = true;
                break;
            case 'traversal': $path = $app.'/../private/Origin.php';
                $canonical[$path] = $base.'/private/Origin.php';
                break;
            case 'normalized traversal': $path = $app.'/Services/../Services/Origin.php';
                $canonical[$path] = $appFile;
                break;
            case 'relative input': $path = 'app/Services/Origin.php';
                break;
            case 'missing file': unset($canonical[$appFile]);
                break;
            case 'non regular file': unset($files[$appFile]);
                break;
            case 'unresolvable root': unset($canonical[$app]);
                break;
            case 'file symlink': $links[$appFile] = true;
                $canonical[$appFile] = $base.'/private/Origin.php';
                break;
            case 'app directory symlink': $links[$app.'/Services'] = true;
                $canonical[$app.'/Services'] = $base.'/private';
                $canonical[$appFile] = $base.'/private/Origin.php';
                break;
            case 'sdk directory symlink': $path = $sdkFile;
                $links[$sdk.'/src'] = true;
                $canonical[$sdk.'/src'] = $base.'/private';
                $canonical[$sdkFile] = $base.'/private/Origin.php';
                break;
            case 'link inside root': $links[$app.'/Services'] = true;
                break;
            case 'root symlink': $links[$app] = true;
                break;
            case 'canonical escape without link flag': $canonical[$app.'/Services'] = $base.'/private';
                break;
            case 'filesystem exception': break;
        }
        if ($windows && ! str_starts_with($scenario, 'mixed ')) {
            $path = str_replace('/', '\\', $path);
        }
        $probe = static function (string $operation, string $path) use ($canonical, $files, $links, $scenario): mixed {
            if ($scenario === 'filesystem exception') {
                throw new \RuntimeException('Synthetic filesystem failure');
            }
            $path = str_replace('\\', '/', $path);

            return match ($operation) {
                'realpath' => $canonical[$path] ?? false,
                'is_link' => $links[$path] ?? false,
                'is_file' => $files[$path] ?? false,
            };
        };
        $policy = new \ReflectionMethod(PaymentPreferenceFailureDiagnostics::class, 'resolveOriginFile');
        $actual = $policy->invoke(null, $path, [
            ['path' => $app, 'component' => 'app', 'prefix' => 'app/'],
            ['path' => $sdk, 'component' => 'mercadopago_sdk', 'prefix' => 'vendor/mercadopago/dx-php/'],
        ], $probe);
        $this->assertTrue($actual === $expected, 'Origin policy must accept only canonical authorized files without links or ambiguous paths.');
    }

    public static function physicalOriginPolicies(): array
    {
        $datasets = [];
        foreach ([false, true] as $windows) {
            foreach (['valid app', 'valid sdk', 'mixed windows first', 'mixed posix first', 'similar app prefix', 'foreign vendor', 'similar sdk prefix', 'traversal', 'normalized traversal', 'relative input', 'missing file', 'non regular file', 'unresolvable root', 'file symlink', 'app directory symlink', 'sdk directory symlink', 'link inside root', 'root symlink', 'canonical escape without link flag', 'filesystem exception'] as $scenario) {
                $datasets[($windows ? 'windows ' : 'posix ').$scenario] = [$windows, $scenario];
            }
        }

        return $datasets;
    }

    private function runtimeFailure(string $privateArgument, string $prefix, int $severity, string $file, int $line): \ErrorException
    {
        // Private arguments exist on the stack; diagnostics must never inspect or serialize it.
        return new \ErrorException($prefix.$privateArgument, 0, $severity, $file, $line);
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
        $expectedKeys = ['exception_class', 'exception_code', 'http_status', 'provider_error_code', 'message', 'provider_request_id', 'order_id', 'payment_preference_id', 'gateway', 'stage', 'origin_component', 'origin_file', 'origin_line', 'error_category'];
        sort($keys);
        sort($expectedKeys);
        $this->assertTrue($keys === $expectedKeys, 'Diagnostic context must contain exactly the allowed keys.');
        foreach ($context as $key => $value) {
            $validType = match ($key) {
                'exception_code', 'http_status', 'origin_line' => $value === null || is_int($value),
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
        $this->assertTrue(in_array($context['origin_component'], ['app', 'mercadopago_sdk', 'vendor', 'unknown'], true), 'Runtime component must match its allowlist.');
        $this->assertTrue($context['origin_file'] === 'unknown' || (preg_match('/\A(?:app\/|vendor\/mercadopago\/dx-php\/)(?:[A-Za-z_][A-Za-z0-9_-]{0,63}\/)*[A-Za-z_][A-Za-z0-9_]{0,63}\.php\z/', $context['origin_file']) === 1 && strlen($context['origin_file']) <= 240), 'Runtime file must be an authorized relative PHP path or unknown.');
        $this->assertTrue($context['origin_line'] === null || ($context['origin_line'] > 0 && $context['origin_line'] <= 1000000), 'Runtime line must be safely bounded.');
        $this->assertTrue(in_array($context['error_category'], ['undefined_array_key', 'undefined_property', 'deprecated_runtime', 'invalid_argument_shape', 'filesystem_or_permission', 'network_runtime', 'unknown_runtime_error'], true), 'Runtime category must match its allowlist.');
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
