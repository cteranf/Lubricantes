<?php

use App\Models\Order;
use App\Services\MockPaymentPreferenceResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

// Safe coordination evidence only: no requests, SQL, identifiers or exception messages.
[$orderId, $action, $role, $directory] = array_slice($argv, 1) + array_fill(0, 4, null);
$started = microtime(true);
$diagnostic = ['role' => in_array($role, ['first', 'second'], true) ? $role : 'invalid', 'action' => in_array($action, ['approve', 'get', 'reject'], true) ? $action : 'invalid', 'attempt' => 0, 'last_checkpoint' => null, 'failure_stage' => null, 'result' => 'starting', 'signature_valid' => false, 'sqlstate' => null, 'driver_code' => null, 'deadlock_1213' => false, 'lock_timeout_1205' => false, 'timeline' => []];
$connected = false;
$publish = function (string $path, array $payload): void {
    $data = json_encode($payload, JSON_THROW_ON_ERROR);
    $stream = @fopen($path.'.tmp', 'wb');
    if ($stream === false) {
        throw new RuntimeException('marker_write_failed');
    }
    try {
        if (fwrite($stream, $data) !== strlen($data) || ! fflush($stream)) {
            throw new RuntimeException('marker_write_failed');
        }
        if (function_exists('fsync') && ! fsync($stream)) {
            throw new RuntimeException('marker_write_failed');
        }
    } finally {
        fclose($stream);
    }
    if (! rename($path.'.tmp', $path)) {
        throw new RuntimeException('marker_publish_failed');
    }
};
$diagnosticPath = is_string($directory) && is_dir($directory) && preg_match('/\Alubristore-mock-race-[a-f0-9]{16}\z/', basename($directory)) && in_array($role, ['first', 'second'], true) ? $directory.'/diagnostic-'.$role.'.json' : null;
$checkpoint = function (string $name) use (&$diagnostic, $started, $diagnosticPath): void {
    $diagnostic['last_checkpoint'] = $name;
    $diagnostic['timeline'][] = ['role' => $diagnostic['role'], 'attempt' => $diagnostic['attempt'], 'checkpoint' => $name, 'elapsed_ms' => (int) round((microtime(true) - $started) * 1000)];
    if ($diagnosticPath) {
        file_put_contents($diagnosticPath, json_encode($diagnostic), LOCK_EX);
    }
};
$captureException = function (Throwable $exception) use (&$diagnostic, $checkpoint): void {
    $diagnostic['failure_stage'] = $diagnostic['last_checkpoint'];
    $info = $exception instanceof \Illuminate\Database\QueryException ? $exception->errorInfo : ($exception instanceof PDOException ? $exception->errorInfo : null);
    $diagnostic['sqlstate'] = is_array($info) ? (string) ($info[0] ?? '') : null;
    $diagnostic['driver_code'] = is_array($info) ? (int) ($info[1] ?? 0) : null;
    $diagnostic['deadlock_1213'] = $diagnostic['driver_code'] === 1213;
    $diagnostic['lock_timeout_1205'] = $diagnostic['driver_code'] === 1205;
    $diagnostic['result'] = $diagnostic['deadlock_1213'] ? 'deadlock' : ($diagnostic['lock_timeout_1205'] ? 'lock_timeout' : ($exception instanceof \Illuminate\Validation\ValidationException ? 'domain_conflict' : 'unexpected_error'));
    $checkpoint('exception_caught');
};
$checkpoint('worker_started');
$exitCode = 0;

try {
    require dirname(__DIR__, 2).'/vendor/autoload.php';
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $checkpoint('bootstrap_completed');
    app(\Illuminate\Contracts\Debug\ExceptionHandler::class)->reportable($captureException);
    [$orderId, $action, $role, $directory] = array_slice($argv, 1) + array_fill(0, 4, null);
    if (count($argv) !== 5 || ! ctype_digit((string) $orderId)
        || ! in_array($action, ['approve', 'get', 'reject'], true) || ! in_array($role, ['first', 'second'], true)
        || ! is_dir((string) $directory) || ! preg_match('/\Alubristore-mock-race-[a-f0-9]{16}\z/', basename($directory))
        || app()->environment() !== 'testing' || config('app.debug') || app()->configurationIsCached()
        || config('database.default') !== 'mysql' || config('database.connections.mysql.url')
        || ! preg_match('/\Alubristore_mock_atomic_[a-f0-9]{12}_test\z/', (string) config('database.connections.mysql.database'))
        || getenv('MOCK_PAYMENT_PHYSICAL_CERT') !== '1') {
        throw new RuntimeException('guard');
    }
    $database = DB::selectOne('SELECT DATABASE() AS name')->name;
    if ($database !== config('database.connections.mysql.database')) {
        throw new RuntimeException('guard');
    }
    $connected = true;
    // Identity stays in private coordination markers, never normal worker output.
    $identity = function () {
        $connection = DB::connection();
        $row = $connection->selectOne('SELECT CONNECTION_ID() AS id, @@server_uuid AS uuid, @@port AS port, DATABASE() AS db, @@autocommit AS ac, @@transaction_isolation AS isolation');

        return ['connection_id' => (int) $row->id, 'server_hash' => hash('sha256', $row->uuid), 'port' => (int) $row->port, 'database' => str_ends_with($row->db, '_test') ? $row->db : null, 'driver' => $connection->getDriverName(), 'laravel_connection' => $connection->getName(), 'transaction_level' => $connection->transactionLevel(), 'autocommit' => (int) $row->ac, 'isolation' => $row->isolation];
    };
    $diagnostic['connection_id'] = $identity()['connection_id'];
    DB::connection()->beforeExecuting(function ($query) use (&$diagnostic, $identity, $directory, $role, $checkpoint, $publish) {
        if (str_contains($query, '`orders`') && str_contains(strtolower($query), 'for update')) {
            $identityNow = $identity();
            $diagnostic['lock_requested'] = true;
            $checkpoint('before_order_lock');
            $path = $directory.'/'.$role.'-before-order-lock';
            $publish($path, ['identity' => $identityNow, 'attempt' => $diagnostic['attempt']]);
        }
    });
    $checkpoint('preflight_completed');
    $events = app('events');
    $events->listen(\Illuminate\Database\Events\TransactionCommitted::class, function ($event) use ($checkpoint) {
        if ($event->connection->transactionLevel() === 0) {
            $checkpoint('committed');
        }
    });
    $events->listen(\Illuminate\Database\Events\TransactionBeginning::class, fn () => $checkpoint('transaction_started'));
    $events->listen(\Illuminate\Database\Events\TransactionRolledBack::class, fn () => $checkpoint('rolled_back'));
    config(['payment.mock.enabled' => true, 'session.driver' => 'array']);
    $app->instance(MockPaymentPreferenceResolver::class, new class($directory, $role, $checkpoint, $diagnostic, $identity, $publish) extends MockPaymentPreferenceResolver
    {
        private array $diagnostic;

        public function __construct(private string $directory, private string $role, private Closure $checkpoint, array &$diagnostic, private Closure $identity, private Closure $publish)
        {
            $this->diagnostic = &$diagnostic;
        }

        public function resolve(string $paymentId, mixed $orderId, bool $lockOrder = false): Order
        {
            if ($lockOrder) {
                $this->diagnostic['attempt']++;
            }
            ($this->checkpoint)('attempt_started');
            ($this->checkpoint)('order_locking');
            $order = parent::resolve($paymentId, $orderId, $lockOrder);
            ($this->checkpoint)('preference_resolved');

            return $order;
        }

        protected function afterOrderLocked(Order $order): void
        {
            $this->diagnostic['lock_acquired'] = true;
            ($this->checkpoint)('order_locked');
            ($this->checkpoint)('hook_entered');
            if ($this->role !== 'first') {
                return;
            }
            ($this->publish)($this->directory.'/first-lock-acquired', ['identity' => ($this->identity)(), 'attempt' => $this->diagnostic['attempt']]);
            ($this->checkpoint)('own_marker_written');
            ($this->checkpoint)('waiting_peer_marker');
            $deadline = microtime(true) + 10;
            do {
                clearstatcache(true, $this->directory.'/release');
                if (is_file($this->directory.'/release')) {
                    ($this->checkpoint)('peer_marker_seen');
                    ($this->checkpoint)('preference_resolving');

                    return;
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            throw new RuntimeException('barrier_timeout');
        }
    });
    $order = Order::findOrFail($orderId);
    $preference = $order->currentPaymentPreference;
    $checkpoint('fixture_loaded');
    if ($action === 'get') {
        Illuminate\Support\Carbon::setTestNow($order->reserved_until->copy()->addMinute());
    }
    $route = $action === 'get' ? 'mock.payment' : 'mock.payment.process';
    $url = URL::temporarySignedRoute($route, now()->addMinutes(30), ['paymentId' => $preference->provider_preference_id, 'order_id' => $order->id]);
    $session = app('session')->driver();
    $session->start();
    $request = $action === 'get'
        ? Illuminate\Http\Request::create($url, 'GET')
        : Illuminate\Http\Request::create($url, 'POST', ['_token' => $session->token(), 'action' => $action, 'scenario' => $action === 'reject' ? 'declined' : 'approved']);
    $diagnostic['signature_valid'] = URL::hasValidSignature($request);
    $checkpoint('signature_checked');
    if (! $diagnostic['signature_valid']) {
        throw new RuntimeException('signature_guard');
    }
    $kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
    $checkpoint($action === 'get' ? 'expiration_started' : 'approval_started');
    $checkpoint('before_transaction');
    $response = $kernel->handle($request);
    $checkpoint('service_returned');
    $kernel->terminate($request, $response);
    $diagnostic['http_status'] = $response->getStatusCode();
    $diagnostic['result'] = $response->getStatusCode() === 302 ? 'success' : 'http_error';
    $checkpoint('result_validation');
    $result = 'finished';
} catch (Throwable $exception) {
    $captureException($exception);
    $result = 'worker_error';
    $exitCode = 1;
} finally {
    if ($connected) {
        $diagnostic['transaction_level_final'] = DB::connection()->transactionLevel();
        DB::disconnect();
    }
    if (class_exists(\Illuminate\Support\Carbon::class)) {
        Illuminate\Support\Carbon::setTestNow();
    }
    $checkpoint('worker_finished');
}
echo json_encode(['result' => $result, 'status' => $diagnostic['http_status'] ?? null, 'diagnostic' => $diagnostic]);
exit($exitCode);
