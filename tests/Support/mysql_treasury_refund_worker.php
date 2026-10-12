<?php

use App\Models\Order;
use App\Services\InventoryBalanceLockService;
use App\Services\TreasuryPaymentRefundService;
use App\Services\TreasuryPaymentService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

$arguments = array_slice($argv, 1);
[$orderId, $actorId, $peerOrderId, $readyPath, $releasePath, $role, $deadlockDirectory, $action] = $arguments + array_fill(0, 8, null);

if (count($arguments) !== 8
    || $action !== 'mark_pending_deadlock'
    || ! is_numeric($orderId)
    || ! is_numeric($actorId)
    || ! is_numeric($peerOrderId)
    || ! in_array($role, ['first', 'second'], true)
    || ! is_dir($deadlockDirectory)
    || ! is_writable($deadlockDirectory)
    || dirname($readyPath) !== $deadlockDirectory
    || dirname($releasePath) !== $deadlockDirectory
    || basename($readyPath) !== 'ready-'.$role
    || basename($releasePath) !== 'release') {
    echo json_encode(['result' => 'worker_error', 'ok' => false, 'stage' => 'arguments']);
    exit(2);
}

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
DB::purge();
DB::reconnect();

$database = DB::selectOne('SELECT DATABASE() AS name')->name ?? null;
$version = DB::selectOne('SELECT VERSION() AS version')->version ?? '';
if (app()->environment() !== 'testing'
    || config('app.debug')
    || DB::connection()->getDriverName() !== 'mysql'
    || $database !== 'lubristore_2b4_resolution_certification_test'
    || ! str_ends_with((string) $database, '_test')
    || $database === 'lubricantes'
    || ! str_starts_with((string) $version, '8.')) {
    echo json_encode(['result' => 'worker_error', 'ok' => false, 'stage' => 'guard']);
    exit(2);
}

$readyPayload = json_encode(['ready' => true, 'role' => $role]);
if (! is_string($readyPayload)
    || @file_put_contents($readyPath, $readyPayload, LOCK_EX) !== strlen($readyPayload)
    || ! is_file($readyPath)) {
    echo json_encode(['result' => 'worker_error', 'ok' => false, 'stage' => 'ready']);
    exit(2);
}

$released = false;
$releaseDeadline = microtime(true) + 20;
while (microtime(true) < $releaseDeadline) {
    clearstatcache(true, $releasePath);
    if (@file_get_contents($releasePath) === 'release') {
        $released = true;
        break;
    }
    usleep(20_000);
}
if (! $released) {
    echo json_encode(['result' => 'release_timeout', 'ok' => false, 'stage' => 'release']);
    exit(2);
}

$stage = 'service_construction';
try {
    $service = new class(app(InventoryBalanceLockService::class), app(TreasuryPaymentService::class), (int) $peerOrderId, $role, $deadlockDirectory) extends TreasuryPaymentRefundService
    {
        public int $attempts = 0;

        /** @var array<int, array{order: int, transaction: int, actor: int}> */
        public array $hydrations = [];

        /** @var array<int, array{order: object, transaction: object, actor: object}> */
        private array $hydratedModels = [];

        /** @var array<int, array{sqlstate: string|null, driver_code: int|null}> */
        public array $concurrencyFailures = [];

        public function __construct(
            InventoryBalanceLockService $locks,
            TreasuryPaymentService $treasury,
            private int $peerOrderId,
            private string $role,
            private string $deadlockDirectory,
        ) {
            parent::__construct($locks, $treasury);
        }

        protected function afterFinancialStateLocked(array $state): void
        {
            $this->attempts++;
            $this->hydrations[] = [
                'order' => spl_object_id($state['order']),
                'transaction' => spl_object_id($state['originalTransaction']),
                'actor' => spl_object_id($state['actor']),
            ];
            $this->hydratedModels[] = [
                'order' => $state['order'],
                'transaction' => $state['originalTransaction'],
                'actor' => $state['actor'],
            ];
            if ($this->attempts !== 1) {
                return;
            }

            $ownSignal = $this->deadlockDirectory.DIRECTORY_SEPARATOR.'deadlock-'.$this->role;
            $peerRole = $this->role === 'first' ? 'second' : 'first';
            $peerSignal = $this->deadlockDirectory.DIRECTORY_SEPARATOR.'deadlock-'.$peerRole;
            if (@file_put_contents($ownSignal, 'ready', LOCK_EX) !== 5) {
                throw new RuntimeException('Deadlock synchronization failed.');
            }

            $deadline = microtime(true) + 10;
            while (microtime(true) < $deadline) {
                clearstatcache(true, $peerSignal);
                if (@file_get_contents($peerSignal) === 'ready') {
                    try {
                        Order::query()->whereKey($this->peerOrderId)->lockForUpdate()->firstOrFail();
                    } catch (QueryException $exception) {
                        $errorInfo = is_array($exception->errorInfo) ? $exception->errorInfo : [];
                        $this->concurrencyFailures[] = [
                            'sqlstate' => is_string($errorInfo[0] ?? null) ? $errorInfo[0] : null,
                            'driver_code' => is_numeric($errorInfo[1] ?? null) ? (int) $errorInfo[1] : null,
                        ];

                        throw $exception;
                    }

                    return;
                }
                usleep(20_000);
            }

            throw new RuntimeException('Deadlock peer synchronization timed out.');
        }
    };

    $stage = 'model_loading';
    $order = Order::findOrFail((int) $orderId);
    $actor = App\Models\User::findOrFail((int) $actorId);
    $stage = 'mark_pending';
    $refund = $service->markPending($order, $actor, 'Solicitud de certificación física');
    $stage = 'result_validation';
    $freshHydration = count($service->hydrations) < 2
        || collect($service->hydrations)->pluck('order')->unique()->count() === count($service->hydrations)
            && collect($service->hydrations)->pluck('transaction')->unique()->count() === count($service->hydrations)
            && collect($service->hydrations)->pluck('actor')->unique()->count() === count($service->hydrations);

    echo json_encode([
        'result' => 'success',
        'ok' => true,
        'status' => $refund->status,
        'attempts' => $service->attempts,
        'attempt_numbers' => range(1, $service->attempts),
        'concurrency_failures' => $service->concurrencyFailures,
        'retry_observed' => $service->attempts > 1,
        'fresh_hydration' => $freshHydration,
        'release_observed' => true,
    ]);
    exit(0);
} catch (Throwable $exception) {
    $query = $exception instanceof QueryException;
    $errorInfo = $query && is_array($exception->errorInfo) ? $exception->errorInfo : [];
    echo json_encode([
        'result' => $query ? 'database_error' : 'unexpected_error',
        'ok' => false,
        'stage' => $stage,
        'exception_class' => $exception::class,
        'sqlstate' => $query && is_string($errorInfo[0] ?? null) ? $errorInfo[0] : null,
        'driver_code' => $query && is_numeric($errorInfo[1] ?? null) ? (int) $errorInfo[1] : null,
        'release_observed' => true,
    ]);
    exit(1);
}
