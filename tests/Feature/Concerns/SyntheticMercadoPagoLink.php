<?php

namespace Tests\Feature\Concerns;

use App\Models\Order;
use App\Models\User;
use App\Services\PaymentPreferenceService;
use MercadoPago\MercadoPagoConfig;
use MercadoPago\Net\MPHttpClient;
use MercadoPago\Net\MPRequest;
use MercadoPago\Net\MPResponse;
use PDOException;
use RuntimeException;
use Throwable;

trait SyntheticMercadoPagoLink
{
    private const MYSQL_CONNECTION_REFUSED = 'mysql_connection_refused';

    private const MYSQL_ACCESS_DENIED = 'mysql_access_denied';

    private const MYSQL_DATABASE_MISSING = 'mysql_database_missing';

    private const MYSQL_CONNECTION_TIMEOUT = 'mysql_connection_timeout';

    private const MYSQL_TLS_FAILURE = 'mysql_tls_failure';

    private const MYSQL_CONNECTION_UNCLASSIFIED = 'mysql_connection_unclassified';

    private const NON_PDO_CONNECTION_FAILURE = 'non_pdo_connection_failure';

    private bool $mysqlPreflightPdoOpened = false;

    private bool $mysqlPreflightSelectAttempted = false;

    private function syntheticDatabaseGuardAllows(array $facts, bool $effective = true): bool
    {
        if (($facts['environment'] ?? null) !== 'testing' || ($facts['debug'] ?? null) !== false
            || ! in_array($facts['driver'] ?? null, ['sqlite', 'mysql'], true)
            || ($facts['external_credentials_absent'] ?? false) !== true
            || ! empty($facts['url']) || ! empty($facts['read']) || ! empty($facts['write'])) {
            return false;
        }
        if ($facts['driver'] === 'sqlite') {
            return ($facts['database'] ?? null) === ':memory:' && (! $effective
                || (($facts['effective_driver'] ?? null) === 'sqlite' && ($facts['effective_database'] ?? null) === ':memory:'));
        }
        $name = $facts['database'] ?? null;
        $ordinary = $facts['ordinary_database'] ?? null;
        if (! is_string($name) || preg_match('/\A[A-Za-z][A-Za-z0-9_]*_test\z/', $name) !== 1
            || strcasecmp($name, 'lubricantes') === 0
            || ! is_string($ordinary) || $ordinary === '' || strcasecmp($name, $ordinary) === 0
            || ! in_array($facts['host'] ?? null, ['127.0.0.1', 'localhost', '::1'], true)
            || ! empty($facts['unix_socket'])
            || ! ctype_digit((string) ($facts['port'] ?? '')) || (int) $facts['port'] < 1 || (int) $facts['port'] > 65535) {
            return false;
        }

        return ! $effective || (($facts['effective_driver'] ?? null) === 'mysql'
            && ($facts['effective_database'] ?? null) === $name
            && ($facts['effective_local'] ?? false) === true
            && (int) ($facts['effective_port'] ?? 0) === (int) $facts['port']);
    }

    private function assertSyntheticDatabaseIsolation(): void
    {
        $this->assertTrue(app()->environment('testing') && config('app.debug') === false
            && ! app()->configurationIsCached(), 'Synthetic database requires isolated testing configuration.');
        $driver = config('database.default');
        $connection = is_string($driver) ? config('database.connections.'.$driver, []) : [];
        $payment = config('payment.mercadopago', []);
        $externalCredentialsAbsent = true;
        foreach ([
            'MERCADOPAGO_PUBLIC_KEY' => 'public_key',
            'MERCADOPAGO_ACCESS_TOKEN' => 'access_token',
            'MERCADOPAGO_WEBHOOK_SECRET' => 'webhook_secret',
        ] as $environmentKey => $configKey) {
            $environmentValue = getenv($environmentKey);
            $configuredValue = is_array($payment) ? ($payment[$configKey] ?? null) : null;
            if (($environmentValue !== false && $environmentValue !== '')
                || (is_string($configuredValue) && $configuredValue !== '')) {
                $externalCredentialsAbsent = false;
            }
        }
        $facts = array_merge(is_array($connection) ? $connection : [], [
            'environment' => app()->environment(), 'debug' => config('app.debug'), 'driver' => $driver,
            'external_credentials_absent' => $externalCredentialsAbsent,
        ]);
        $this->assertTrue(($connection['driver'] ?? null) === $driver, 'Synthetic database driver must match its connection.');
        if ($driver === 'mysql') {
            $ordinary = null;
            try {
                $contents = @file_get_contents(base_path('.env'));
                if (is_string($contents)) {
                    $ordinary = \Dotenv\Dotenv::parse($contents)['DB_DATABASE'] ?? null;
                }
            } catch (\Throwable) {
                // Fail closed below without printing any ordinary configuration.
            }
            $facts['ordinary_database'] = $ordinary;
        }
        $this->assertTrue($this->syntheticDatabaseGuardAllows($facts, false), 'Synthetic database configuration is not authorized.');
        // Read-only preflight: never probe an endpoint before its configuration
        // passes the local-only guard. Do not expose a connection error's text.
        $this->mysqlPreflightPdoOpened = false;
        $this->mysqlPreflightSelectAttempted = false;
        try {
            $effective = \Illuminate\Support\Facades\DB::connection();
            $pdo = $effective->getPdo();
            $this->mysqlPreflightPdoOpened = true;
            $facts['effective_driver'] = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
            if ($driver === 'mysql') {
                $this->mysqlPreflightSelectAttempted = true;
                $server = $pdo->query('SELECT DATABASE() AS name, @@port AS port')->fetch(\PDO::FETCH_ASSOC);
                $facts['effective_database'] = $server['name'] ?? null;
                $facts['effective_port'] = $server['port'] ?? null;
                $status = $pdo->getAttribute(\PDO::ATTR_CONNECTION_STATUS);
                $facts['effective_local'] = is_string($status)
                    && preg_match('/\A(?:127\.0\.0\.1|localhost|::1) via TCP\/IP\z/i', $status) === 1;
            } else {
                $databases = $pdo->query('PRAGMA database_list')->fetchAll(\PDO::FETCH_ASSOC);
                $main = array_values(array_filter($databases, static fn ($row) => $row['name'] === 'main'));
                $facts['effective_database'] = count($main) === 1 && $main[0]['file'] === '' ? ':memory:' : null;
            }
        } catch (Throwable $failure) {
            $category = $this->classifyConnectionFailure($failure);
            throw $this->safeConnectionFailure($category);
        }
        $this->assertTrue($this->syntheticDatabaseGuardAllows($facts), 'Synthetic database effective connection is not authorized.');
    }

    /** Classify only recognized numeric PDO driver codes; never inspect messages. */
    private function classifyConnectionFailure(Throwable $failure, bool $lockWaitTimeoutConfirmed = false): string
    {
        $seen = [];
        $foundPdo = false;
        $current = $failure;

        for ($depth = 0; $current !== null && $depth < 8; $depth++) {
            $objectId = spl_object_id($current);
            if (isset($seen[$objectId])) {
                break;
            }
            $seen[$objectId] = true;

            if ($current instanceof PDOException) {
                $foundPdo = true;
                $code = $this->numericDriverCode($current);
                if ($code !== null) {
                    if ($code === '2002' || $code === '2003') {
                        return self::MYSQL_CONNECTION_REFUSED;
                    }
                    if ($code === '1045') {
                        return self::MYSQL_ACCESS_DENIED;
                    }
                    if ($code === '1049') {
                        return self::MYSQL_DATABASE_MISSING;
                    }
                    if ($code === '1205' && $lockWaitTimeoutConfirmed) {
                        return self::MYSQL_CONNECTION_TIMEOUT;
                    }
                }
            }

            $current = $current->getPrevious();
        }

        return $foundPdo ? self::MYSQL_CONNECTION_UNCLASSIFIED : self::NON_PDO_CONNECTION_FAILURE;
    }

    private function numericDriverCode(PDOException $exception): ?string
    {
        $errorInfo = $exception->errorInfo ?? null;
        if (is_array($errorInfo) && array_key_exists(1, $errorInfo)) {
            $code = $errorInfo[1];
            if (is_int($code) && $code >= 0) {
                return (string) $code;
            }
            if (is_string($code) && preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $code) === 1) {
                return $code;
            }
        }

        $code = $exception->getCode();
        $recognized = ['2002', '2003', '1045', '1049', '1205'];
        if (is_int($code) && in_array((string) $code, $recognized, true)) {
            return (string) $code;
        }
        if (is_string($code) && in_array($code, $recognized, true)) {
            return $code;
        }

        return null;
    }

    private function safeConnectionFailure(string $category): RuntimeException
    {
        $allowed = [
            self::MYSQL_CONNECTION_REFUSED,
            self::MYSQL_ACCESS_DENIED,
            self::MYSQL_DATABASE_MISSING,
            self::MYSQL_CONNECTION_TIMEOUT,
            self::MYSQL_TLS_FAILURE,
            self::MYSQL_CONNECTION_UNCLASSIFIED,
            self::NON_PDO_CONNECTION_FAILURE,
        ];
        if (! in_array($category, $allowed, true)) {
            $category = self::MYSQL_CONNECTION_UNCLASSIFIED;
        }

        return new SyntheticDatabaseIsolationException($category);
    }

    private function withRemoteLink(\Closure $callback): void
    {
        $this->assertSyntheticDatabaseIsolation();
        $property = new \ReflectionProperty(MercadoPagoConfig::class, 'http_client');
        $original = $property->getValue();
        $token = MercadoPagoConfig::getAccessToken();
        $transport = new class implements MPHttpClient
        {
            public array $payment = [];

            public array $merchant = [];

            public array $calls = [];

            public bool $missing = false;

            public ?\Throwable $paymentFailure = null;

            public ?\Throwable $merchantFailure = null;

            public int $paymentId;

            public string $preferenceId;

            public function __construct()
            {
                $this->preferenceId = 'synthetic-'.bin2hex(random_bytes(16));
            }

            public function send(MPRequest $request): MPResponse
            {
                $this->calls[] = [$request->getMethod(), $request->getUri()];
                if ($request->getMethod() === 'POST' && $request->getUri() === '/checkout/preferences') {
                    return new MPResponse(201, [
                        'id' => $this->preferenceId,
                        'init_point' => 'https://example.invalid/checkout',
                        'sandbox_init_point' => 'https://example.invalid/sandbox',
                    ]);
                }
                if ($request->getMethod() === 'GET' && $request->getUri() === '/v1/payments/'.$this->paymentId) {
                    if ($this->paymentFailure) {
                        throw $this->paymentFailure;
                    }

                    return new MPResponse(200, $this->payment);
                }
                if ($request->getMethod() === 'GET' && $request->getUri() === '/merchant_orders/'.($this->payment['order']['id'] ?? '')) {
                    if ($this->merchantFailure) {
                        throw $this->merchantFailure;
                    }
                    if ($this->missing) {
                        throw new \MercadoPago\Exceptions\MPApiException('Synthetic missing merchant order', new MPResponse(404, ['message' => 'not found']));
                    }

                    return new MPResponse(200, $this->merchant);
                }
                throw new \LogicException('Unexpected SDK request; no network transport exists.');
            }
        };
        try {
            config(['payment.mercadopago.access_token' => 'SYNTHETIC_'.bin2hex(random_bytes(16)), 'payment.currency' => 'PEN']);
            MercadoPagoConfig::setHttpClient($transport);
            $order = Order::create(['user_id' => User::factory()->create()->id, 'status' => 'pending', 'total' => 10.25, 'shipping_info' => [], 'payment_method' => 'card', 'payment_status' => 'pending', 'delivery_type' => 'delivery', 'tracking_status' => 'pending']);
            $preference = app(PaymentPreferenceService::class)->createFor($order, [
                'items' => [['name' => 'Synthetic item', 'quantity' => 1, 'price' => 10.25]],
                'customer' => ['name' => 'Synthetic buyer', 'email' => 'buyer@example.invalid'],
            ], 'mercadopago');
            $this->assertSame('active', $preference->state);
            $paymentId = random_int(1000000, 1000000000);
            $transport->paymentId = $paymentId;
            $merchantId = random_int(1000000001, 2000000000);
            $transport->payment = ['id' => $paymentId, 'order' => ['id' => $merchantId, 'type' => 'mercadopago'], 'status' => 'approved', 'status_detail' => 'accredited', 'external_reference' => $preference->external_reference, 'transaction_amount' => 10.25, 'currency_id' => 'PEN', 'payment_method_id' => 'visa'];
            $transport->merchant = ['id' => $merchantId, 'preference_id' => $preference->provider_preference_id, 'external_reference' => $preference->external_reference, 'total_amount' => 10.25, 'payments' => [['id' => $paymentId, 'transaction_amount' => 10.25, 'currency_id' => 'PEN']]];
            $transport->calls = [];
            $callback($transport, $order->refresh(), $preference);
        } finally {
            $property->setValue(null, $original);
            MercadoPagoConfig::setAccessToken($token);
        }
    }
}

final class SyntheticDatabaseIsolationException extends RuntimeException
{
    private const ALLOWED_CATEGORIES = [
        'mysql_connection_refused',
        'mysql_access_denied',
        'mysql_database_missing',
        'mysql_connection_timeout',
        'mysql_tls_failure',
        'mysql_connection_unclassified',
        'non_pdo_connection_failure',
    ];

    private string $safeCategory;

    public function __construct(string $category)
    {
        $this->safeCategory = self::normalizeCategory($category);
        parent::__construct('MySQL isolation preflight failed: '.$this->safeCategory);
    }

    public function category(): string
    {
        return $this->safeCategory;
    }

    public function __serialize(): array
    {
        return ['category' => $this->safeCategory];
    }

    public function __unserialize(array $data): void
    {
        $category = $data['category'] ?? null;
        $this->safeCategory = self::normalizeCategory(is_string($category) ? $category : '');
        parent::__construct('MySQL isolation preflight failed: '.$this->safeCategory);
    }

    private static function normalizeCategory(string $category): string
    {
        return in_array($category, self::ALLOWED_CATEGORIES, true)
            ? $category
            : 'mysql_connection_unclassified';
    }
}
