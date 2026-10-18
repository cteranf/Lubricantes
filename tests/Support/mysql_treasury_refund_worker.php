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
    || ! in_array($action, ['mark_pending_deadlock', 'complete_same', 'complete_different', 'complete_deadlock', 'complete_snapshot'], true)
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
if (app()->environment() !== 'testing' || config('app.debug')
    || config('database.default') !== 'mysql'
    || config('database.connections.mysql.database') !== 'lubristore_2b4_resolution_certification_test'
    || config('database.connections.mysql.url')) {
    echo json_encode(['result' => 'worker_error', 'ok' => false, 'stage' => 'configuration_guard']);
    exit(2);
}
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
foreach (['2026_09_26_070600_create_payment_refunds_table', '2026_09_26_070620_add_refund_pending_to_order_payment_status', '2026_10_11_070630_generalize_payment_refunds_for_total_refunds'] as $migration) {
    if (DB::table('migrations')->where('migration', $migration)->count() !== 1) {
        echo json_encode(['result' => 'worker_error', 'ok' => false, 'stage' => 'migration_guard']);
        exit(2);
    }
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
$startedAt = microtime(true);
$diagnostic = (object) [
    'failure_point' => null,
    'last_checkpoint' => null,
    'own_marker_written' => false,
    'peer_marker_seen' => false,
    'own_marker_exists_at_failure' => null,
    'peer_marker_exists_at_failure' => null,
    'transaction_level_at_failure' => null,
    'checkpoints' => [],
];
$ownMarker = $deadlockDirectory.DIRECTORY_SEPARATOR.'deadlock-'.$role;
$peerMarker = $deadlockDirectory.DIRECTORY_SEPARATOR.'deadlock-'.($role === 'first' ? 'second' : 'first');
$checkpoint = static function (string $point) use ($diagnostic, $startedAt): void {
    $allowed = ['before_complete', 'financial_state_locking', 'hook_entered', 'own_marker_written', 'waiting_peer_marker', 'peer_marker_seen', 'requesting_peer_order', 'peer_order_locked', 'retry_entered', 'result_validation', 'locking_submission', 'locked_submission', 'locking_order', 'locked_order', 'locking_transactions', 'locked_transactions', 'locking_refunds', 'locked_refunds', 'barrier_marker_write_failed', 'barrier_peer_timeout', 'database_concurrency_error', 'unexpected_error', 'snapshot_peer_timeout', 'snapshot_precondition_failed', 'snapshot_commit_marker_failed'];
    if (in_array($point, $allowed, true)) {
        $diagnostic->last_checkpoint = $point;
        $diagnostic->checkpoints[] = ['checkpoint' => $point, 'elapsed_ms' => (int) round((microtime(true) - $startedAt) * 1000)];
    }
};
$failure = static function (string $point) use ($diagnostic, $checkpoint, $ownMarker, $peerMarker): void {
    $diagnostic->failure_point = $point;
    clearstatcache(true, $ownMarker);
    clearstatcache(true, $peerMarker);
    $diagnostic->own_marker_exists_at_failure = is_file($ownMarker);
    $diagnostic->peer_marker_exists_at_failure = is_file($peerMarker);
    $diagnostic->transaction_level_at_failure = DB::transactionLevel();
    $checkpoint($point);
};
$safeDiagnostic = static function () use ($diagnostic, $role, &$stage, &$service): array {
    return [
        'role' => $role, 'stage' => $stage,
        'failure_point' => $diagnostic->failure_point,
        'hook_count' => $service->attempts ?? 0,
        'attempts' => $service->attempts ?? 0,
        'transaction_level' => DB::transactionLevel(),
        'transaction_level_at_failure' => $diagnostic->transaction_level_at_failure,
        'own_marker_written' => $diagnostic->own_marker_written,
        'peer_marker_seen' => $diagnostic->peer_marker_seen,
        'own_marker_exists_at_failure' => $diagnostic->own_marker_exists_at_failure,
        'peer_marker_exists_at_failure' => $diagnostic->peer_marker_exists_at_failure,
        'last_checkpoint' => $diagnostic->last_checkpoint,
        'concurrency_failures' => $service->concurrencyFailures ?? [],
        'checkpoints' => $diagnostic->checkpoints,
    ];
};
try {
    $service = new class(app(InventoryBalanceLockService::class), app(TreasuryPaymentService::class), (int) $peerOrderId, $role, $deadlockDirectory, $action, $diagnostic, $checkpoint, $failure) extends TreasuryPaymentRefundService
    {
        public int $attempts = 0;

        public ?App\Models\PaymentRefund $lockedRefund = null;

        public array $completionOrderHydrations = [];

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
            private string $action,
            private object $diagnostic,
            private Closure $checkpoint,
            private Closure $failure,
        ) {
            parent::__construct($locks, $treasury);
        }

        protected function afterFinancialStateLocked(array $state): void
        {
            $this->recordAttempt($state);
        }

        protected function afterCompletionFinancialStateLocked(array $state): void
        {
            $this->lockedRefund = $state['refunds']->first();
            $this->recordAttempt($state);
        }

        protected function afterCompletionOrderLocked(Order $order): void
        {
            if ($this->action !== 'complete_deadlock') {
                return;
            }
            if (! $order->exists || (int) $order->id === $this->peerOrderId || DB::transactionLevel() !== 1) {
                ($this->failure)('unexpected_error');
                throw new RuntimeException('Order lock precondition failed.');
            }
            $this->attempts++;
            $this->completionOrderHydrations[] = $order;
            ($this->checkpoint)('hook_entered');
            if ($this->attempts > 1) {
                ($this->checkpoint)('retry_entered');

                return;
            }
            $this->coordinateDeadlock();
        }

        private function recordAttempt(array $state): void
        {
            if ($this->action !== 'complete_deadlock') {
                $this->attempts++;
                ($this->checkpoint)('hook_entered');
            }
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
            if ($this->attempts !== 1 || $this->action !== 'mark_pending_deadlock') {
                return;
            }
            $this->coordinateDeadlock();
        }

        private function coordinateDeadlock(): void
        {
            $ownSignal = $this->deadlockDirectory.DIRECTORY_SEPARATOR.'deadlock-'.$this->role;
            $peerRole = $this->role === 'first' ? 'second' : 'first';
            $peerSignal = $this->deadlockDirectory.DIRECTORY_SEPARATOR.'deadlock-'.$peerRole;
            if (@file_put_contents($ownSignal, 'ready', LOCK_EX) !== 5) {
                ($this->failure)('barrier_marker_write_failed');
                throw new RuntimeException('Deadlock synchronization failed.');
            }
            $this->diagnostic->own_marker_written = true;
            ($this->checkpoint)('own_marker_written');
            ($this->checkpoint)('waiting_peer_marker');

            $deadline = microtime(true) + 10;
            while (microtime(true) < $deadline) {
                clearstatcache(true, $peerSignal);
                if (@file_get_contents($peerSignal) === 'ready') {
                    $this->diagnostic->peer_marker_seen = true;
                    ($this->checkpoint)('peer_marker_seen');
                    try {
                        ($this->checkpoint)('requesting_peer_order');
                        Order::query()->whereKey($this->peerOrderId)->lockForUpdate()->firstOrFail();
                        ($this->checkpoint)('peer_order_locked');
                    } catch (QueryException|Illuminate\Database\DeadlockException $exception) {
                        $source = $exception instanceof QueryException ? $exception : $exception->getPrevious();
                        $errorInfo = $source instanceof QueryException && is_array($source->errorInfo) ? $source->errorInfo : [];
                        $this->concurrencyFailures[] = [
                            'sqlstate' => is_string($errorInfo[0] ?? null) ? $errorInfo[0] : null,
                            'driver_code' => is_numeric($errorInfo[1] ?? null) ? (int) $errorInfo[1] : null,
                        ];
                        ($this->checkpoint)('database_concurrency_error');

                        throw $exception;
                    }

                    return;
                }
                usleep(20_000);
            }

            ($this->failure)('barrier_peer_timeout');
            throw new RuntimeException('Deadlock peer synchronization timed out.');
        }
    };

    $stage = 'model_loading';
    if ($action === 'mark_pending_deadlock') {
        $order = Order::findOrFail((int) $orderId);
        $actor = App\Models\User::findOrFail((int) $actorId);
        $stage = 'mark_pending';
        $refund = $service->markPending($order, $actor, 'Solicitud de certificación física');
    } else {
        $refundId = App\Models\PaymentRefund::query()->whereHas('originalPaymentTransaction', fn ($query) => $query->where('order_id', (int) $orderId))->sole()->id;
        $stage = 'complete';
        // Synthetic references stay in memory and never enter argv or diagnostics.
        $reference = $action === 'complete_different' ? 'certification-fixture-'.$role : 'certification-fixture';
        $snapshotPath = $deadlockDirectory.DIRECTORY_SEPARATOR.'snapshot-pending';
        $committedPath = $deadlockDirectory.DIRECTORY_SEPARATOR.'completion-committed';
        $waitForSignal = static function (string $path) use ($failure): void {
            $deadline = microtime(true) + 20;
            while (microtime(true) < $deadline) {
                if (@file_get_contents($path) === 'ready') {
                    return;
                }
                usleep(20_000);
            }
            $failure('snapshot_peer_timeout');
            throw new RuntimeException('Snapshot synchronization timed out.');
        };
        $snapshotPending = false;
        if ($action === 'complete_snapshot') {
            if ($role === 'first') {
                DB::beginTransaction();
                $snapshotPending = App\Models\PaymentRefund::findOrFail($refundId)->status === App\Models\PaymentRefund::PENDING;
                if (! $snapshotPending || file_put_contents($snapshotPath, 'ready', LOCK_EX) !== 5) {
                    $failure('snapshot_precondition_failed');
                    throw new RuntimeException('Snapshot precondition failed.');
                }
                $waitForSignal($committedPath);
            } else {
                $waitForSignal($snapshotPath);
            }
        }
        $checkpoint('before_complete');
        $refund = $service->complete((int) $refundId, (int) $actorId, $reference);
        $lockedCompleted = $service->lockedRefund?->status === App\Models\PaymentRefund::COMPLETED;
        if ($action === 'complete_snapshot') {
            if ($role === 'first') {
                DB::commit();
            } elseif (file_put_contents($committedPath, 'ready', LOCK_EX) !== 5) {
                $failure('snapshot_commit_marker_failed');
                throw new RuntimeException('Commit synchronization failed.');
            }
        }
    }
    $stage = 'result_validation';
    $checkpoint('result_validation');
    $freshHydration = count($service->hydrations) < 2
        || collect($service->hydrations)->pluck('order')->unique()->count() === count($service->hydrations)
            && collect($service->hydrations)->pluck('transaction')->unique()->count() === count($service->hydrations)
            && collect($service->hydrations)->pluck('actor')->unique()->count() === count($service->hydrations);
    if ($action === 'complete_deadlock') {
        $freshHydration = $freshHydration
            && count($service->completionOrderHydrations) === $service->attempts
            && count(array_unique(array_map(spl_object_id(...), $service->completionOrderHydrations))) === $service->attempts;
    }

    echo json_encode([
        'result' => 'success',
        'ok' => true,
        'status' => $refund->status,
        'same_locked_instance' => $action === 'mark_pending_deadlock' ? null : $refund === $service->lockedRefund,
        'snapshot_pending' => $snapshotPending ?? false,
        'locked_completed' => $lockedCompleted ?? false,
        'completed_by' => $refund->completed_by,
        'refund_id' => $refund->id,
        'accounting_id' => $refund->refund_payment_transaction_id,
        'attempts' => $service->attempts,
        'attempt_numbers' => range(1, $service->attempts),
        'concurrency_failures' => $service->concurrencyFailures,
        'retry_observed' => $service->attempts > 1,
        'fresh_hydration' => $freshHydration,
        'release_observed' => true,
        'diagnostic' => $safeDiagnostic(),
    ]);
    exit(0);
} catch (App\Exceptions\PaymentRefundConflictException) {
    echo json_encode(['result' => 'conflict', 'ok' => false, 'stage' => $stage, 'release_observed' => true]);
    exit(0);
} catch (Throwable $exception) {
    $query = $exception instanceof QueryException;
    $errorInfo = $query && is_array($exception->errorInfo) ? $exception->errorInfo : [];
    if ($diagnostic->failure_point === null) {
        $failure($query && (($errorInfo[0] ?? null) === '40001' || in_array((int) ($errorInfo[1] ?? 0), [1213, 1205], true)) ? 'database_concurrency_error' : 'unexpected_error');
    }
    echo json_encode([
        'result' => $query ? 'database_error' : 'unexpected_error',
        'ok' => false,
        'stage' => $stage,
        'exception_class' => $exception::class,
        'previous_exception_class' => $exception->getPrevious() ? $exception->getPrevious()::class : null,
        'sqlstate' => $query && is_string($errorInfo[0] ?? null) ? $errorInfo[0] : null,
        'driver_code' => $query && is_numeric($errorInfo[1] ?? null) ? (int) $errorInfo[1] : null,
        'release_observed' => true,
        'diagnostic' => $safeDiagnostic(),
    ]);
    exit(1);
}
