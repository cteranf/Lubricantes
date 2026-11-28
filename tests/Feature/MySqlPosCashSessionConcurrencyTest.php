<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Order;
use App\Models\PosCashierAssignment;
use App\Models\PosCashSession;
use App\Models\PosRegister;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\PosCashSessionService;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process;
use Tests\TestCase;

#[Group('mysql')]
class MySqlPosCashSessionConcurrencyTest extends TestCase
{
    private string $database;

    private string $directory;

    private string $connectionConfigFile;

    private ?Connection $observer = null;

    /** @var array<int, Process> */
    private array $workers = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('POS cash session races require MySQL/InnoDB.');
        }
        $database = getenv('LARAVEL13_MYSQL_TEST_DATABASE');
        $this->assertSame('lubristore_pos_foundation_20261125_test', $database);
        $this->database = $database;
        $this->assertSame('testing', app()->environment());
        $this->assertFalse((bool) config('app.debug'));
        $this->assertFalse(app()->configurationIsCached());
        $this->assertSame('mysql', config('database.default'));
        $this->assertSame($database, config('database.connections.mysql.database'));
        $this->assertSame($database, DB::selectOne('SELECT DATABASE() AS name')->name);
        $this->assertSame('INNODB', strtoupper((string) DB::selectOne('SELECT @@default_storage_engine AS engine')->engine));
        $this->assertSame('8.4.3', substr((string) DB::selectOne('SELECT VERSION() AS version')->version, 0, 5));
        // This suite may reset only the exact disposable schema above.
        Artisan::call('migrate:fresh', ['--force' => true]);
        $engines = DB::table('information_schema.tables')->selectRaw('ENGINE AS engine')->where('table_schema', $database)->whereIn('table_name', ['pos_registers', 'pos_cash_sessions', 'orders'])->pluck('engine')->map(fn ($engine) => strtoupper((string) $engine));
        $this->assertSame(['INNODB', 'INNODB', 'INNODB'], $engines->sort()->values()->all());

        $this->directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pos-cash-race-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700, true);
        $this->connectionConfigFile = $this->directory.DIRECTORY_SEPARATOR.'connection.json';
        file_put_contents($this->connectionConfigFile, json_encode(config('database.connections.mysql'), JSON_THROW_ON_ERROR), LOCK_EX);
        @chmod($this->connectionConfigFile, 0600);
    }

    protected function tearDown(): void
    {
        if (isset($this->directory) && is_dir($this->directory)) {
            @touch($this->path('release'));
        }
        foreach ($this->workers as $worker) {
            if ($worker->isRunning()) {
                $worker->stop(1);
            }
        }
        if (isset($this->directory) && is_dir($this->directory)) {
            foreach (glob($this->directory.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
            @rmdir($this->directory);
        }
        DB::purge('pos_cash_lock_observer');
        parent::tearDown();
    }

    public function test_two_independent_connections_opening_same_register_leave_one_session_and_one_actor(): void
    {
        [$register, $cashier] = $this->fixture();
        $observer = $this->observer();
        $firstKey = (string) Str::uuid();
        $secondKey = (string) Str::uuid();
        $first = $this->startWorker('open', 'hold', $register->id, $cashier->id, '25.00', $firstKey, 'open-a');
        $firstIdentity = json_decode($this->waitForFile('open-a.ready'), true, flags: JSON_THROW_ON_ERROR);
        $second = $this->startWorker('open', 'run', $register->id, $cashier->id, '25.00', $secondKey, 'open-b', 'start');
        $secondIdentity = json_decode($this->waitForFile('open-b.ready'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertNotSame($firstIdentity['connection_id'], $secondIdentity['connection_id']);
        touch($this->path('start'));
        $this->waitForFile('open-b.started');
        $this->waitForLockWait($observer, (int) $secondIdentity['connection_id'], (int) $firstIdentity['connection_id'], 'pos_registers');
        touch($this->path('release'));
        $first->wait();
        $second->wait();
        $firstResult = $this->readWorkerResult($first, 'open-a');
        $secondResult = $this->readWorkerResult($second, 'open-b');

        $this->assertTrue($firstResult['ok']);
        $this->assertFalse($secondResult['ok']);
        $this->assertSame(409, $secondResult['http_status']);
        $this->assertSame(1, PosCashSession::query()->where('register_id', $register->id)->where('status', 'open')->count());
        $session = PosCashSession::query()->where('register_id', $register->id)->sole();
        $this->assertSame($cashier->id, (int) $session->cashier_user_id);
        $this->assertSame($firstResult['session_id'], $session->id);
        $this->assertSame(1, DB::table('pos_register_open_sessions')->where('register_id', $register->id)->count());
        $this->assertSame(1, DB::table('pos_cashier_open_sessions')->where('cashier_user_id', $cashier->id)->count());
    }

    public function test_two_independent_connections_closing_the_same_session_record_one_close(): void
    {
        [$register, $cashier] = $this->fixture();
        $session = app(PosCashSessionService::class)->open($register, $cashier, '40.00', (string) Str::uuid());
        $observer = $this->observer();
        $firstKey = (string) Str::uuid();
        $secondKey = (string) Str::uuid();
        $first = $this->startWorker('close', 'hold', $register->id, $cashier->id, '35.00', $firstKey, 'close-a', sessionId: $session->id, reason: 'Conteo concurrente A');
        $firstIdentity = json_decode($this->waitForFile('close-a.ready'), true, flags: JSON_THROW_ON_ERROR);
        $second = $this->startWorker('close', 'run', $register->id, $cashier->id, '35.00', $secondKey, 'close-b', 'start', $session->id, 'Conteo concurrente B');
        $secondIdentity = json_decode($this->waitForFile('close-b.ready'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertNotSame($firstIdentity['connection_id'], $secondIdentity['connection_id']);
        touch($this->path('start'));
        $this->waitForFile('close-b.started');
        $this->waitForLockWait($observer, (int) $secondIdentity['connection_id'], (int) $firstIdentity['connection_id'], 'pos_registers');
        touch($this->path('release'));
        $first->wait();
        $second->wait();
        $firstResult = $this->readWorkerResult($first, 'close-a');
        $secondResult = $this->readWorkerResult($second, 'close-b');

        $this->assertTrue($firstResult['ok']);
        $this->assertFalse($secondResult['ok']);
        $this->assertSame(409, $secondResult['http_status']);
        $closed = $session->fresh();
        $this->assertSame('closed', $closed->status);
        $this->assertSame($cashier->id, (int) $closed->closed_by);
        $this->assertSame($firstKey, $closed->closing_idempotency_key);
        $this->assertSame('40.00', $closed->expected_cash);
        $this->assertSame('35.00', $closed->counted_cash);
        $this->assertSame('-5.00', $closed->difference);
        $this->assertSame('Conteo concurrente A', $closed->closing_reason);
        $this->assertSame(1, DB::table('pos_audit_events')->where('session_id', $session->id)->where('event', 'session_closed')->count());
        $this->assertSame(1, DB::table('pos_cash_movements')->where('session_id', $session->id)->where('kind', 'opening')->count());
        $this->assertDatabaseMissing('pos_register_open_sessions', ['register_id' => $register->id]);
        $this->assertDatabaseMissing('pos_cashier_open_sessions', ['cashier_user_id' => $cashier->id]);
    }

    public function test_pos_migration_rollback_and_reapply_preserve_existing_orders(): void
    {
        $buyer = User::factory()->create(['role' => 'customer', 'is_active' => true]);
        $order = Order::create([
            'user_id' => $buyer->id, 'status' => 'pending', 'subtotal' => '75.00', 'total' => '75.00',
            'shipping_amount' => '0.00', 'shipping_info' => ['address' => 'Synthetic legacy order'],
            'payment_method' => 'transferencia', 'payment_status' => 'pending',
            'delivery_type' => 'pickup', 'tracking_status' => 'pending',
        ]);
        $id = (int) $order->id;

        $this->assertSame(0, Artisan::call('migrate:rollback', [
            '--path' => 'database/migrations/2026_11_25_080000_create_pos_cash_foundation.php',
            '--force' => true,
        ]), Artisan::output());
        $this->assertTrue(Schema::hasTable('orders'));
        $this->assertFalse(Schema::hasTable('pos_registers'));
        $this->assertFalse(Schema::hasColumn('orders', 'sales_channel'));
        $this->assertSame('75.00', DB::table('orders')->where('id', $id)->value('total'));
        $this->assertSame($buyer->id, (int) DB::table('orders')->where('id', $id)->value('user_id'));

        $this->assertSame(0, Artisan::call('migrate', [
            '--path' => 'database/migrations/2026_11_25_080000_create_pos_cash_foundation.php',
            '--force' => true,
        ]), Artisan::output());
        $this->assertTrue(Schema::hasTable('pos_cash_sessions'));
        $this->assertSame('web', DB::table('orders')->where('id', $id)->value('sales_channel'));
        $this->assertSame('75.00', DB::table('orders')->where('id', $id)->value('total'));
    }

    private function fixture(): array
    {
        $suffix = Str::lower(Str::random(8));
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $cashier = User::factory()->create(['role' => 'treasury', 'is_active' => true]);
        $branch = Branch::create(['code' => 'POS-'.$suffix, 'name' => 'POS concurrency', 'address' => 'Test', 'is_active' => true]);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'code' => 'POS-'.$suffix, 'name' => 'POS concurrency', 'is_active' => true]);
        $register = PosRegister::create(['warehouse_id' => $warehouse->id, 'code' => 'POS-'.$suffix, 'name' => 'Register race', 'is_active' => true, 'created_by' => $admin->id]);
        PosCashierAssignment::create(['register_id' => $register->id, 'user_id' => $cashier->id, 'assigned_by' => $admin->id, 'is_active' => true, 'assigned_at' => now()]);

        return [$register, $cashier];
    }

    private function startWorker(string $action, string $mode, int $registerId, int $cashierId, string $amount, string $key, string $label, string $start = '-', ?int $sessionId = null, ?string $reason = null): Process
    {
        $process = new Process([
            PHP_BINARY, base_path('tests/Support/mysql_pos_cash_worker.php'), $this->connectionConfigFile,
            $action, $mode, (string) $registerId, (string) $cashierId, $amount, $key,
            (string) ($sessionId ?? 0), (string) ($reason ?? ''), $this->path($label.'.ready'), $this->path($label.'.started'),
            $start === '-' ? '-' : $this->path($start), $this->path('release'), $this->path($label.'.result'),
        ], base_path(), null, null, 25);
        $process->start();
        $this->workers[] = $process;

        return $process;
    }

    private function readWorkerResult(Process $process, string $label): array
    {
        $this->assertSame(0, $process->getExitCode(), 'The POS concurrency worker did not complete cleanly.');

        return json_decode($this->waitForFile($label.'.result'), true, flags: JSON_THROW_ON_ERROR);
    }

    private function observer(): Connection
    {
        $config = DB::connection()->getConfig();
        config(['database.connections.pos_cash_lock_observer' => $config]);
        $this->observer = DB::connection('pos_cash_lock_observer');
        $identity = $this->observer->selectOne('SELECT CONNECTION_ID() AS id, DATABASE() AS database_name');
        $this->assertSame($this->database, $identity->database_name);
        $this->assertNotSame((int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id, (int) $identity->id);
        $this->assertSame(1, (int) $this->observer->selectOne('SELECT @@performance_schema AS enabled')->enabled);

        return $this->observer;
    }

    private function waitForLockWait(Connection $observer, int $requesting, int $blocking, string $table): void
    {
        $deadline = microtime(true) + 10;
        do {
            $wait = $observer->selectOne(
                "SELECT COUNT(*) AS n FROM performance_schema.data_lock_waits w
                 JOIN performance_schema.threads r ON r.THREAD_ID = w.REQUESTING_THREAD_ID
                 JOIN performance_schema.threads b ON b.THREAD_ID = w.BLOCKING_THREAD_ID
                 JOIN performance_schema.data_locks requesting_lock ON requesting_lock.ENGINE = w.ENGINE AND requesting_lock.ENGINE_LOCK_ID = w.REQUESTING_ENGINE_LOCK_ID
                 JOIN performance_schema.data_locks blocking_lock ON blocking_lock.ENGINE = w.ENGINE AND blocking_lock.ENGINE_LOCK_ID = w.BLOCKING_ENGINE_LOCK_ID
                 WHERE r.PROCESSLIST_ID = ? AND b.PROCESSLIST_ID = ? AND requesting_lock.OBJECT_SCHEMA = DATABASE()
                   AND requesting_lock.OBJECT_NAME = ? AND requesting_lock.LOCK_STATUS = 'WAITING'
                   AND blocking_lock.LOCK_STATUS = 'GRANTED'",
                [$requesting, $blocking, $table]
            );
            if ((int) $wait->n > 0) {
                return;
            }
            usleep(10_000);
        } while (microtime(true) < $deadline);
        $observed = $observer->select(
            'SELECT r.PROCESSLIST_ID AS requesting_id, b.PROCESSLIST_ID AS blocking_id,
                    l.OBJECT_NAME AS table_name, l.INDEX_NAME AS index_name, l.LOCK_TYPE AS lock_type,
                    l.LOCK_STATUS AS lock_status
             FROM performance_schema.data_lock_waits w
             JOIN performance_schema.threads r ON r.THREAD_ID = w.REQUESTING_THREAD_ID
             JOIN performance_schema.threads b ON b.THREAD_ID = w.BLOCKING_THREAD_ID
             JOIN performance_schema.data_locks l ON l.ENGINE = w.ENGINE AND l.ENGINE_LOCK_ID = w.REQUESTING_ENGINE_LOCK_ID
             WHERE r.PROCESSLIST_ID IN (?, ?) OR b.PROCESSLIST_ID IN (?, ?)',
            [$requesting, $blocking, $requesting, $blocking]
        );
        $threads = $observer->select(
            'SELECT PROCESSLIST_ID AS connection_id, PROCESSLIST_COMMAND AS command, PROCESSLIST_STATE AS state, PROCESSLIST_TIME AS seconds_in_state FROM performance_schema.threads WHERE PROCESSLIST_ID IN (?, ?)',
            [$requesting, $blocking]
        );
        $locks = $observer->select(
            'SELECT t.PROCESSLIST_ID AS connection_id, l.OBJECT_NAME AS table_name, l.INDEX_NAME AS index_name, l.LOCK_TYPE AS lock_type, l.LOCK_MODE AS lock_mode, l.LOCK_STATUS AS lock_status FROM performance_schema.data_locks l JOIN performance_schema.threads t ON t.THREAD_ID = l.THREAD_ID WHERE t.PROCESSLIST_ID IN (?, ?)',
            [$requesting, $blocking]
        );
        $this->fail('MySQL did not expose the expected POS row-lock wait. Threads: '.json_encode($threads, JSON_THROW_ON_ERROR).'; locks: '.json_encode($locks, JSON_THROW_ON_ERROR).'; waits: '.json_encode($observed, JSON_THROW_ON_ERROR));
    }

    private function waitForFile(string $name): string
    {
        $path = $this->path($name);
        $deadline = microtime(true) + 20;
        while (! is_file($path) && microtime(true) < $deadline) {
            usleep(20_000);
        }
        $this->assertFileExists($path, 'A POS MySQL worker did not reach its synchronization point.');

        return trim((string) file_get_contents($path));
    }

    private function path(string $name): string
    {
        return $this->directory.DIRECTORY_SEPARATOR.$name;
    }
}
