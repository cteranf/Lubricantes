<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\InventoryService;
use App\Services\PaymentPreferenceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class MySqlMockPaymentCheckoutConcurrencyTest extends TestCase
{
    // Retain only safe coordination evidence; never print raw process output.
    private array $workerMetadata = [];

    private array $observation = [];

    /** @dataProvider winners */
    public function test_physical_approval_and_get_expiration_preserve_current_state(string $winner, int $iteration): void
    {
        $this->assertSame('testing', app()->environment());
        $this->assertFalse((bool) config('app.debug'));
        $this->assertFalse(app()->configurationIsCached());
        $this->assertSame('mysql', config('database.default'));
        $this->assertEmpty(config('database.connections.mysql.url'));
        $this->assertTrue(preg_match('/\Alubristore_mock_atomic_[a-f0-9]{12}_test\z/', (string) config('database.connections.mysql.database')) === 1);
        $this->assertSame('1', getenv('MOCK_PAYMENT_PHYSICAL_CERT'));
        $this->assertTrue(DB::selectOne('SELECT DATABASE() AS name')->name === config('database.connections.mysql.database'));
        $this->assertSame(1, DB::table('migrations')->where('migration', '2026_09_04_070500_create_payment_preferences_table')->count());
        $probe = $this->requireObserver();
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'lubristore-mock-race-'.bin2hex(random_bytes(8));
        $workers = [];
        $order = null;
        $product = null;
        $user = null;
        try {
            $this->assertTrue(mkdir($directory));
            config(['payment.mock.enabled' => true]);
            $user = User::factory()->create(['role' => 'customer']);
            $tag = Str::lower(Str::random(12));
            $product = Product::create(['name' => 'Mock race fixture', 'slug' => 'mock-'.$tag, 'sku' => 'MOCK-'.$tag, 'price' => 50, 'is_active' => true]);
            $inventory = app(InventoryService::class);
            $warehouse = $inventory->defaultWarehouse();
            $inventory->initializeProduct($product, 10, $warehouse);
            $order = Order::create(['user_id' => $user->id, 'status' => 'pending', 'subtotal' => 50, 'total' => 50, 'shipping_amount' => 0, 'shipping_info' => ['address' => 'Synthetic fixture'], 'payment_method' => 'card', 'payment_status' => 'pending', 'delivery_type' => 'delivery', 'tracking_status' => 'pending', 'reserved_until' => now()->addMinutes(30)]);
            $item = $order->items()->create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'quantity' => 1, 'price' => 50, 'subtotal' => 50]);
            $item->setRelation('product', $product);
            $item->setRelation('warehouse', $warehouse);
            $inventory->reserveForOrder($item, $order->reserved_until);
            app(PaymentPreferenceService::class)->createFor($order, ['order_id' => $order->id], 'mock');
            $this->assertSame(0, DB::connection()->transactionLevel(), 'Race fixtures must be committed before workers start.');
            $this->assertNull($order->refresh()->payment_id);
            $visible = $probe->table('orders')->where('id', $order->id)->whereNotNull('current_payment_preference_id')->count() === 1
                    && $probe->table('payment_preferences')->where('order_id', $order->id)->where('gateway', 'mock')->where('state', 'active')->count() === 1
                    && $probe->table('inventory_reservations')->where('order_id', $order->id)->where('status', 'active')->count() === 1;
            $this->assertTrue($visible, 'Committed fixtures must be visible from an independent connection.');
            foreach (['first' => $winner, 'second' => $winner === 'approve' ? 'get' : 'approve'] as $role => $action) {
                $worker = new Process([PHP_BINARY, base_path('tests/Support/mysql_mock_checkout_worker.php'), (string) $order->id, $action, $role, $directory], base_path(), array_merge(getenv(), ['APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'DB_CONNECTION' => 'mysql']));
                $worker->setTimeout(15);
                $worker->start();
                $this->workerMetadata[$role] = ['role' => $role, 'action' => $action];
                $workers[$role] = $worker;
                if ($role === 'first') {
                    $this->waitUntil(fn () => is_file($directory.'/first-lock-acquired'), $workers);
                }
            }
            $this->waitUntil(fn () => is_file($directory.'/second-before-order-lock'), $workers);
            $observationStarted = microtime(true);
            $this->observation['timeline'][] = ['checkpoint' => 'second_prelock_visible_polling_started', 'elapsed_ms' => 0];
            $this->waitUntil(function () use ($directory, $probe, $observationStarted) {
                $first = json_decode(file_get_contents($directory.'/first-lock-acquired'), true);
                $second = json_decode(file_get_contents($directory.'/second-before-order-lock'), true);
                $firstId = $first['identity']['connection_id'] ?? null;
                $secondId = $second['identity']['connection_id'] ?? null;
                if (! $firstId || ! $secondId) {
                    return false;
                }
                foreach ([$first['identity'], $second['identity']] as $identity) {
                    foreach (['server_hash', 'port', 'database', 'driver'] as $key) {
                        $this->assertTrue($identity[$key] === $this->observation['observer'][$key], 'Workers and observer must use the same isolated server.');
                    }
                    $this->assertNotSame($identity['connection_id'], $this->observation['observer']['connection_id']);
                    $this->assertGreaterThan(0, $identity['transaction_level']);
                }
                $this->assertNotSame($firstId, $secondId);
                $this->observation['actual_lock_ids'] = ['blocking' => $firstId, 'requesting' => $secondId];
                // Se usa el marcador previo y el par exacto de Performance Schema para hacer determinista la coordinación.
                $pair = $probe->selectOne("SELECT COUNT(*) AS n FROM performance_schema.data_lock_waits w JOIN performance_schema.threads r ON r.THREAD_ID=w.REQUESTING_THREAD_ID JOIN performance_schema.threads b ON b.THREAD_ID=w.BLOCKING_THREAD_ID JOIN performance_schema.data_locks l ON l.ENGINE_LOCK_ID=w.REQUESTING_ENGINE_LOCK_ID AND l.ENGINE=w.ENGINE JOIN performance_schema.data_locks bl ON bl.ENGINE_LOCK_ID=w.BLOCKING_ENGINE_LOCK_ID AND bl.ENGINE=w.ENGINE WHERE r.PROCESSLIST_ID=? AND b.PROCESSLIST_ID=? AND l.OBJECT_SCHEMA=? AND l.OBJECT_NAME='orders' AND l.INDEX_NAME='PRIMARY' AND l.LOCK_TYPE='RECORD' AND l.LOCK_STATUS='WAITING' AND bl.OBJECT_SCHEMA=l.OBJECT_SCHEMA AND bl.OBJECT_NAME=l.OBJECT_NAME AND bl.INDEX_NAME='PRIMARY' AND bl.LOCK_TYPE='RECORD' AND bl.LOCK_STATUS='GRANTED'", [$secondId, $firstId, config('database.connections.mysql.database')]);
                $visible = (int) $pair->n > 0;
                $this->observation['exact_performance_schema_pair_visible'] = $visible;
                if ($visible) {
                    if ($this->observation['innodb_trx_accessible']) {
                        $this->observation['innodb_wait_visible'] = $probe->selectOne("SELECT COUNT(*) AS n FROM information_schema.innodb_trx WHERE trx_mysql_thread_id=? AND trx_state='LOCK WAIT'", [$secondId])->n > 0;
                    }
                    $this->observation['timeline'][] = ['checkpoint' => 'exact_wait_pair_visible_release_authorized', 'elapsed_ms' => (int) round((microtime(true) - $observationStarted) * 1000)];
                }

                return $visible;
            }, $workers);
            $this->assertSame(7, file_put_contents($directory.'/release', 'release', LOCK_EX));
            $this->observation['timeline'][] = ['checkpoint' => 'release_published', 'elapsed_ms' => (int) round((microtime(true) - $observationStarted) * 1000)];
            foreach ($workers as $worker) {
                $worker->wait();
                $result = json_decode($worker->getOutput(), true);
                $this->assertTrue($worker->isSuccessful() && ($result['result'] ?? null) === 'finished' && ($result['status'] ?? null) === 302, 'Both physical workers must complete without timeouts, exhausted deadlocks or internal failures.');
                $this->assertTrue(($result['diagnostic']['signature_valid'] ?? false) === true, 'Both workers must validate their exact signed request before coordination.');
                $this->assertTrue(($result['diagnostic']['attempt'] ?? 0) >= 1 && $result['diagnostic']['attempt'] <= 3, 'Both workers must enter the real retryable contract.');
                $this->assertFalse($result['diagnostic']['lock_timeout_1205'] ?? true, 'A lock timeout must never satisfy the race.');
                $this->assertSame(0, $result['diagnostic']['transaction_level_final'] ?? null);
                if ($result['diagnostic']['attempt'] > 1) {
                    $this->assertSame('40001', $result['diagnostic']['sqlstate'] ?? null);
                    $this->assertSame(1213, $result['diagnostic']['driver_code'] ?? null);
                    $this->assertTrue($result['diagnostic']['deadlock_1213'] ?? false);
                }
            }
            $order->refresh();
            $balance = DB::table('warehouse_inventories')->where('product_id', $product->id)->sole();
            $reservation = $order->reservations()->sole();
            if ($winner === 'approve') {
                $this->assertSame('approved', $order->payment_status);
                $this->assertNotNull($order->paid_at);
                $this->assertSame('confirmed', $order->status);
                $this->assertSame('consumed', $reservation->status);
                $this->assertSame(9, (int) $balance->quantity);
                $this->assertSame(1, $order->paymentTransactions()->where('status', 'approved')->count());
                $this->assertSame(1, DB::table('inventory_movements')->where('product_id', $product->id)->where('type', 'sale')->count());
            } else {
                $this->assertSame('canceled', $order->status);
                $this->assertNull($order->paid_at);
                $this->assertSame('pending', $order->payment_status);
                $this->assertSame('expired', $reservation->status);
                $this->assertSame(10, (int) $balance->quantity);
                $this->assertSame(0, $order->paymentTransactions()->count());
                $this->assertSame(0, DB::table('inventory_movements')->where('product_id', $product->id)->where('type', 'sale')->count());
            }
            $this->assertSame(0, (int) $balance->reserved_quantity);
            $this->assertSame(1, $order->fulfillmentHistory()->count());
            $this->assertSame(1, DB::table('payment_preferences')->where('order_id', $order->id)->count());
            $preference = DB::table('payment_preferences')->where('id', $order->current_payment_preference_id)->sole();
            $this->assertTrue((int) $preference->order_id === (int) $order->id && $preference->gateway === 'mock' && $preference->state === 'active', 'The current mock preference must remain coherent.');
            $this->assertSame((int) $balance->quantity, (int) $product->fresh()->stock);
            $this->assertGreaterThanOrEqual(0, (int) $balance->quantity);
            $this->assertSame(0, $order->reservations()->where('status', 'active')->count());
            $this->assertFalse($order->status === 'canceled' && ($order->payment_status === 'approved' || $order->paid_at !== null));
            fwrite(STDOUT, "\nmock race: winner={$winner}; iteration={$iteration}; lock_wait_observed=true; invariant=true\n");
        } finally {
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop(0);
                }
            }
            foreach (['first', 'second'] as $role) {
                $worker = $workers[$role] ?? null;
                $raw = $worker ? $worker->getOutput() : '';
                $decoded = json_decode($raw, true);
                $path = $directory.'/diagnostic-'.$role.'.json';
                $diagnostic = is_array($decoded) ? ($decoded['diagnostic'] ?? null) : null;
                if (! is_array($diagnostic) && is_file($path)) {
                    $diagnostic = json_decode(file_get_contents($path), true);
                }
                $summary = ($this->workerMetadata[$role] ?? ['role' => $role, 'action' => $role === 'first' ? $winner : ($winner === 'approve' ? 'get' : 'approve')])
                    + ['started' => $worker !== null, 'exit_code' => $worker?->getExitCode(), 'stdout_class' => $raw === '' ? 'empty' : (is_array($decoded) ? 'json' : 'non_json'), 'stderr_present' => $worker ? $worker->getErrorOutput() !== '' : false, 'diagnostic' => $diagnostic];
                fwrite(STDOUT, "\nworker_diagnostic: ".json_encode($summary, JSON_THROW_ON_ERROR)."\n");
            }
            fwrite(STDOUT, "\nobservation_diagnostic: ".json_encode(['wait_pair' => $this->observation['actual_lock_ids'] ?? null, 'pair_observed' => $this->observation['exact_performance_schema_pair_visible'] ?? false, 'innodb_wait_visible' => $this->observation['innodb_wait_visible'] ?? null, 'timeline' => $this->observation['timeline'] ?? []], JSON_THROW_ON_ERROR)."\n");
            DB::purge('mock_diagnostic');
            // IDs belong exclusively to this committed fixture; DDL/transaction rollback is not cleanup.
            if ($order) {
                DB::table('orders')->where('id', $order->id)->update(['current_payment_preference_id' => null]);
                foreach (['payment_transactions', 'order_fulfillment_histories', 'inventory_reservations', 'payment_preferences', 'order_items'] as $table) {
                    DB::table($table)->where('order_id', $order->id)->delete();
                }
                DB::table('orders')->where('id', $order->id)->delete();
            }
            if ($product) {
                foreach (['inventory_movements', 'warehouse_inventories'] as $table) {
                    DB::table($table)->where('product_id', $product->id)->delete();
                }
                DB::table('products')->where('id', $product->id)->delete();
            }
            if ($user) {
                DB::table('users')->where('id', $user->id)->delete();
            }
            fwrite(STDOUT, "\nfixture_cleanup: orders=".($order ? DB::table('orders')->where('id', $order->id)->count() : 0).'; preferences='.($order ? DB::table('payment_preferences')->where('order_id', $order->id)->count() : 0).'; reservations='.($order ? DB::table('inventory_reservations')->where('order_id', $order->id)->count() : 0)."\n");
            foreach (['first-lock-acquired', 'first-lock-acquired.tmp', 'release', 'diagnostic-first.json', 'diagnostic-second.json', 'first-before-order-lock', 'second-before-order-lock', 'first-before-order-lock.tmp', 'second-before-order-lock.tmp'] as $name) {
                if (is_file($directory.'/'.$name)) {
                    unlink($directory.'/'.$name);
                }
            }
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }

    private function requireObserver()
    {
        $configuration = DB::connection()->getConfig();
        $configuration['name'] = 'mock_diagnostic';
        config(['database.connections.mock_diagnostic' => $configuration]);
        try {
            $probe = DB::connection('mock_diagnostic');
            $parent = $this->connectionIdentity(DB::connection());
            $observer = $this->connectionIdentity($probe);
            $this->assertNotSame($parent['connection_id'], $observer['connection_id']);
            foreach (['server_hash', 'port', 'database', 'driver'] as $key) {
                $this->assertTrue($parent[$key] === $observer[$key], 'Observer preflight requires the same isolated server.');
            }
            $this->assertTrue((bool) $probe->selectOne('SELECT @@performance_schema AS enabled')->enabled, 'Observer preflight requires Performance Schema.');
            $hasProcess = false;
            foreach ($probe->select('SHOW GRANTS') as $grant) {
                foreach ((array) $grant as $text) {
                    $hasProcess = $hasProcess || preg_match('/\bPROCESS\b|ALL PRIVILEGES ON \*\.\*/', $text) === 1;
                }
            }
            $this->assertTrue($hasProcess, 'Observer preflight requires visibility of other connections.');
            foreach (['performance_schema.data_lock_waits', 'performance_schema.data_locks', 'performance_schema.threads'] as $table) {
                $probe->selectOne('SELECT COUNT(*) AS n FROM '.$table);
            }
            $secondary = true;
            try {
                $probe->selectOne('SELECT COUNT(*) AS n FROM information_schema.innodb_trx');
            } catch (\Illuminate\Database\QueryException) {
                $secondary = false;
            }
            $this->observation = ['observer' => $observer, 'innodb_trx_accessible' => $secondary];

            return $probe;
        } catch (\Throwable $exception) {
            DB::purge('mock_diagnostic');
            if ($exception instanceof \Illuminate\Database\QueryException) {
                $this->fail('Observer preflight not satisfied; no fixtures or workers started.');
            }
            throw $exception;
        }
    }

    private function connectionIdentity($connection): array
    {
        $row = $connection->selectOne('SELECT CONNECTION_ID() AS id, @@server_uuid AS uuid, @@port AS port, DATABASE() AS db, @@autocommit AS ac, @@transaction_isolation AS isolation');

        return ['connection_id' => (int) $row->id, 'server_hash' => hash('sha256', $row->uuid), 'port' => (int) $row->port, 'database' => str_ends_with($row->db, '_test') ? $row->db : null, 'driver' => $connection->getDriverName(), 'laravel_connection' => $connection->getName(), 'transaction_level' => $connection->transactionLevel(), 'autocommit' => (int) $row->ac, 'isolation' => $row->isolation];
    }

    private function waitUntil(callable $condition, array $workers): void
    {
        $deadline = microtime(true) + 10;
        do {
            clearstatcache();
            if ($condition()) {
                return;
            }
            foreach ($workers as $worker) {
                $this->assertTrue($worker->isRunning(), 'A physical worker exited before coordination completed.');
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        $this->fail('Physical mock coordination timed out.');
    }

    public static function winners(): array
    {
        $cases = [];
        for ($iteration = 1; $iteration <= 10; $iteration++) {
            $cases['approval first '.$iteration] = ['approve', $iteration];
            $cases['expiration first '.$iteration] = ['get', $iteration];
        }

        return $cases;
    }
}
