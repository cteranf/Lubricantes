<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\InventoryMovement;
use App\Models\InventoryReceipt;
use App\Models\InventoryReservation;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseInventory;
use App\Services\InventoryReceiptService;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process;
use Tests\TestCase;

#[Group('mysql')]
class MySqlInventoryReceiptConcurrencyTest extends TestCase
{
    private string $database;

    private string $workerDirectory;

    private string $connectionConfigFile;

    private ?Connection $lockObserver = null;

    /** @var array<int, Process> */
    private array $workers = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Inventory receipt concurrency requires MySQL/InnoDB.');
        }

        $database = getenv('LARAVEL13_MYSQL_TEST_DATABASE');
        $this->assertIsString($database, 'Set LARAVEL13_MYSQL_TEST_DATABASE to a dedicated disposable MySQL schema.');
        $this->assertMatchesRegularExpression('/\Alubristore_php85_laravel13_[a-z0-9_]+\z/', $database);
        $this->database = $database;
        $this->assertSame($database, config('database.connections.mysql.database'));
        $this->assertSame($database, DB::connection()->selectOne('SELECT DATABASE() AS database_name')->database_name);
        $this->assertSame('INNODB', strtoupper((string) DB::connection()->selectOne('SELECT @@default_storage_engine AS engine_name')->engine_name));

        // This destructive operation is guarded above and may only touch the
        // dedicated database selected by phpunit.inventory-receipts.mysql.xml.
        Artisan::call('migrate:fresh', ['--force' => true]);
        $this->workerDirectory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'inventory-receipt-'.bin2hex(random_bytes(8));
        mkdir($this->workerDirectory, 0700, true);
        $this->connectionConfigFile = $this->workerDirectory.DIRECTORY_SEPARATOR.'connection.json';
        file_put_contents($this->connectionConfigFile, json_encode(config('database.connections.mysql'), JSON_THROW_ON_ERROR));
    }

    protected function tearDown(): void
    {
        if (isset($this->workerDirectory) && is_dir($this->workerDirectory)) {
            touch($this->workerDirectory.DIRECTORY_SEPARATOR.'release');
        }
        foreach ($this->workers as $worker) {
            if ($worker->isRunning()) {
                $worker->stop(1);
            }
        }
        if (isset($this->workerDirectory) && is_dir($this->workerDirectory)) {
            foreach (glob($this->workerDirectory.DIRECTORY_SEPARATOR.'*') ?: [] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
            rmdir($this->workerDirectory);
        }
        DB::purge('receipt_lock_observer');
        parent::tearDown();
    }

    public function test_two_connections_confirming_same_draft_create_one_movement_and_preserve_first_actor_and_time(): void
    {
        [$adminA, $warehouse, $product, $receipt] = $this->fixture(10, true, 4);
        $adminB = $this->admin('second');
        $before = $receipt->lines()->firstOrFail();
        $probe = $this->requireLockObserver();
        $a = $this->startWorker('hold-header', $receipt, $adminA, 'a');
        $connectionA = (int) $this->waitForFile($this->path('a.ready'));
        $b = $this->startWorker('confirm', $receipt, $adminB, 'b');
        $connectionB = (int) $this->waitForFile($this->path('b.ready'));
        $this->assertNotSame($connectionA, $connectionB);
        $this->assertNotSame($probe->selectOne('SELECT CONNECTION_ID() AS id')->id, $connectionA);
        $this->assertNotSame($probe->selectOne('SELECT CONNECTION_ID() AS id')->id, $connectionB);
        $this->waitForExactLockWait($probe, $connectionB, $connectionA, 'inventory_receipts');
        touch($this->path('release'));
        $resultA = $this->finishWorker($a, 'a');
        $resultB = $this->finishWorker($b, 'b');

        $receipt->refresh();
        $this->assertSame('confirmed', $receipt->status);
        $this->assertSame($adminA->id, $receipt->confirmed_by);
        $this->assertSame($adminA->id, $resultA['confirmed_by']);
        $this->assertSame($adminA->id, $resultB['confirmed_by']);
        $this->assertSame($resultA['confirmed_at'], $receipt->confirmed_at?->toISOString());
        $this->assertSame($resultA['confirmed_at'], $resultB['confirmed_at']);
        $this->assertSame(1, InventoryMovement::query()->where('reference_id', (string) $before->id)->count());
        $this->assertSame(14, WarehouseInventory::query()->where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->value('quantity'));
        $this->assertSame(14, $product->fresh()->stock);
    }

    public function test_two_receipts_for_same_product_serialize_creation_of_missing_balance(): void
    {
        $admin = $this->admin('shared');
        [$branch, $warehouse] = $this->warehouse();
        $source = Warehouse::create(['branch_id' => $branch->id, 'code' => 'S-'.Str::upper(Str::random(8)), 'name' => 'Reservation source', 'is_active' => true]);
        $product = $this->product(5);
        WarehouseInventory::create(['warehouse_id' => $source->id, 'product_id' => $product->id, 'quantity' => 5, 'reserved_quantity' => 2]);
        $reservation = $this->reservationFixture($admin, $source, $product, 2);
        $reservationBefore = $reservation->getRawOriginal();
        $receiptA = $this->draft($warehouse, $product, 6, $admin);
        $receiptB = $this->draft($warehouse, $product, 9, $admin);
        $this->assertSame(0, WarehouseInventory::query()->where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->count());
        $probe = $this->requireLockObserver();
        $workerA = $this->startWorker('hold-warehouses', $receiptA, $admin, 'c');
        $connectionC = (int) $this->waitForFile($this->path('c.ready'));
        $workerB = $this->startWorker('confirm', $receiptB, $admin, 'd');
        $connectionD = (int) $this->waitForFile($this->path('d.ready'));
        $this->assertNotSame($connectionC, $connectionD);
        $this->waitForExactLockWait($probe, $connectionD, $connectionC, 'warehouses');
        touch($this->path('release'));
        $this->finishWorker($workerA, 'c');
        $this->finishWorker($workerB, 'd');

        $balance = WarehouseInventory::query()->where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->firstOrFail();
        $this->assertSame(15, $balance->quantity);
        $this->assertSame(0, $balance->reserved_quantity);
        $this->assertSame(20, $product->fresh()->stock);
        $this->assertSame(2, WarehouseInventory::query()->where('warehouse_id', $source->id)->where('product_id', $product->id)->value('reserved_quantity'));
        $this->assertSame($reservationBefore, $reservation->fresh()->getRawOriginal());
        $this->assertDatabaseCount('inventory_reservations', 1);
        $this->assertSame(2, InventoryMovement::query()->where('type', InventoryMovement::RECEIPT)->where('product_id', $product->id)->count());
        $this->assertSame(2, InventoryMovement::query()->where('product_id', $product->id)->distinct('idempotency_key')->count('idempotency_key'));
        $expectedKeys = collect([$receiptA, $receiptB])->flatMap(fn (InventoryReceipt $receipt) => $receipt->lines->map(fn ($line) => 'inventory-receipt-line-'.$line->id))->sort()->values()->all();
        $actualKeys = InventoryMovement::query()->where('type', InventoryMovement::RECEIPT)->where('product_id', $product->id)->pluck('idempotency_key')->sort()->values()->all();
        $this->assertSame($expectedKeys, $actualKeys);
    }

    public function test_intermediate_sql_failure_rolls_back_and_retry_commits_once(): void
    {
        $admin = $this->admin('failure');
        [, $warehouse] = $this->warehouse();
        $firstProduct = $this->product(8);
        $secondProduct = $this->product(8);
        WarehouseInventory::create(['warehouse_id' => $warehouse->id, 'product_id' => $firstProduct->id, 'quantity' => 8, 'reserved_quantity' => 0]);
        WarehouseInventory::create(['warehouse_id' => $warehouse->id, 'product_id' => $secondProduct->id, 'quantity' => 8, 'reserved_quantity' => 0]);
        $receipt = app(InventoryReceiptService::class)->createDraft([
            'warehouse_id' => $warehouse->id,
            'lines' => [['product_id' => $firstProduct->id, 'quantity' => 5], ['product_id' => $secondProduct->id, 'quantity' => 5]],
        ], $admin);
        DB::statement("CREATE TRIGGER reject_receipt_movement BEFORE INSERT ON inventory_movements FOR EACH ROW BEGIN IF NEW.type = 'receipt' AND NEW.product_id = {$secondProduct->id} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'injected test failure'; END IF; END");
        try {
            try {
                app(InventoryReceiptService::class)->confirm($receipt->id, $receipt->version, $admin);
                $this->fail('El trigger debe abortar el movimiento intermedio.');
            } catch (QueryException) {
                // Expected injected database failure; state is asserted after rollback.
            }
        } finally {
            DB::statement('DROP TRIGGER IF EXISTS reject_receipt_movement');
        }

        $this->assertSame('draft', $receipt->fresh()->status);
        $this->assertSame(8, WarehouseInventory::query()->where('warehouse_id', $warehouse->id)->where('product_id', $firstProduct->id)->value('quantity'));
        $this->assertSame(8, WarehouseInventory::query()->where('warehouse_id', $warehouse->id)->where('product_id', $secondProduct->id)->value('quantity'));
        $this->assertSame(8, $firstProduct->fresh()->stock);
        $this->assertSame(8, $secondProduct->fresh()->stock);
        $this->assertSame(0, InventoryMovement::query()->where('type', InventoryMovement::RECEIPT)->count());
        $confirmed = app(InventoryReceiptService::class)->confirm($receipt->id, $receipt->version, $admin);
        $this->assertSame('confirmed', $confirmed->status);
        $this->assertSame(13, WarehouseInventory::query()->where('warehouse_id', $warehouse->id)->where('product_id', $firstProduct->id)->value('quantity'));
        $this->assertSame(13, WarehouseInventory::query()->where('warehouse_id', $warehouse->id)->where('product_id', $secondProduct->id)->value('quantity'));
        $this->assertSame(13, $firstProduct->fresh()->stock);
        $this->assertSame(13, $secondProduct->fresh()->stock);
        $this->assertSame(2, InventoryMovement::query()->where('type', InventoryMovement::RECEIPT)->count());
    }

    private function fixture(int $quantity, bool $balance, int $received): array
    {
        $admin = $this->admin('first');
        [, $warehouse] = $this->warehouse();
        $product = $this->product($quantity);
        if ($balance) {
            WarehouseInventory::create(['warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'quantity' => $quantity, 'reserved_quantity' => 0]);
        }
        $receipt = $this->draft($warehouse, $product, $received, $admin);

        return [$admin, $warehouse, $product, $receipt];
    }

    private function warehouse(): array
    {
        $branch = Branch::create(['code' => 'B-'.Str::upper(Str::random(8)), 'name' => 'Receipts test', 'address' => 'Test', 'is_active' => true]);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'code' => 'W-'.Str::upper(Str::random(8)), 'name' => 'Receipts test', 'is_active' => true]);

        return [$branch, $warehouse];
    }

    private function product(int $stock): Product
    {
        $product = Product::create(['name' => 'Receipt product', 'slug' => 'receipt-'.Str::uuid(), 'sku' => 'R-'.Str::upper(Str::random(8)), 'price' => 10]);
        $product->forceFill(['stock' => $stock])->save();

        return $product;
    }

    private function admin(string $suffix): User
    {
        return User::factory()->create(['role' => 'admin', 'is_active' => true, 'email' => 'receipt-'.$suffix.'-'.Str::random(8).'@example.test']);
    }

    private function reservationFixture(User $user, Warehouse $warehouse, Product $product, int $quantity): InventoryReservation
    {
        $orderId = DB::table('orders')->insertGetId([
            'user_id' => $user->id,
            'status' => 'pending',
            'total' => '10.00',
            'shipping_info' => json_encode(['address' => 'Test']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $itemId = DB::table('order_items')->insertGetId([
            'order_id' => $orderId,
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => $quantity,
            'price' => '5.00',
            'subtotal' => '10.00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('inventory_reservations')->insert([
            'order_id' => $orderId,
            'order_item_id' => $itemId,
            'reservation_sequence' => 1,
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => $quantity,
            'status' => 'active',
            'expires_at' => now()->addHour(),
            'idempotency_key' => 'mysql-test-reservation-'.$itemId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return InventoryReservation::query()->where('order_item_id', $itemId)->firstOrFail();
    }

    private function draft(Warehouse $warehouse, Product $product, int $quantity, User $admin): InventoryReceipt
    {
        return app(InventoryReceiptService::class)->createDraft([
            'warehouse_id' => $warehouse->id,
            'lines' => [['product_id' => $product->id, 'quantity' => $quantity]],
        ], $admin);
    }

    private function startWorker(string $mode, InventoryReceipt $receipt, User $admin, string $label, string $start = '-'): Process
    {
        $worker = base_path('tests/Support/inventory_receipt_worker.php');
        $process = new Process([
            PHP_BINARY,
            $worker,
            $this->connectionConfigFile,
            $mode,
            (string) $receipt->id,
            (string) $receipt->version,
            (string) $admin->id,
            $this->path($label.'.ready'),
            $start === '-' ? '-' : $this->path($start),
            $this->path('release'),
            $this->path($label.'.result'),
        ], base_path(), null, null, 25);
        $process->start();
        $this->workers[] = $process;

        return $process;
    }

    private function finishWorker(Process $process, string $label): array
    {
        $process->wait();
        $this->assertSame(0, $process->getExitCode(), 'Receipt worker did not complete successfully.');
        $result = json_decode((string) file_get_contents($this->path($label.'.result')), true);
        $this->assertTrue($result['ok'] ?? false);

        return $result;
    }

    private function path(string $name): string
    {
        return $this->workerDirectory.DIRECTORY_SEPARATOR.$name;
    }

    private function waitForFile(string $path): string
    {
        $deadline = microtime(true) + 20;
        while (! is_file($path) && microtime(true) < $deadline) {
            usleep(20_000);
        }
        $this->assertFileExists($path, 'A receipt concurrency worker did not reach its barrier.');

        return trim((string) file_get_contents($path));
    }

    private function requireLockObserver(): Connection
    {
        $configuration = DB::connection()->getConfig();
        $configuration['name'] = 'receipt_lock_observer';
        config(['database.connections.receipt_lock_observer' => $configuration]);
        $this->lockObserver = DB::connection('receipt_lock_observer');
        $identity = $this->lockObserver->selectOne('SELECT CONNECTION_ID() AS id, DATABASE() AS database_name')->id;
        $this->assertSame($this->database, $this->lockObserver->selectOne('SELECT DATABASE() AS database_name')->database_name);
        $this->assertNotSame((int) DB::connection()->selectOne('SELECT CONNECTION_ID() AS id')->id, (int) $identity);
        $this->assertSame(1, (int) $this->lockObserver->selectOne('SELECT @@performance_schema AS enabled')->enabled);

        foreach (['performance_schema.data_lock_waits', 'performance_schema.data_locks', 'performance_schema.threads'] as $table) {
            $this->lockObserver->selectOne('SELECT COUNT(*) AS row_count FROM '.$table);
        }

        return $this->lockObserver;
    }

    private function waitForExactLockWait(Connection $observer, int $requestingConnectionId, int $blockingConnectionId, string $table): void
    {
        $this->assertContains($table, ['inventory_receipts', 'warehouses']);
        $deadline = microtime(true) + 10;
        do {
            $wait = $observer->selectOne(
                "SELECT COUNT(*) AS wait_count
                 FROM performance_schema.data_lock_waits waits
                 JOIN performance_schema.threads requesting_thread ON requesting_thread.THREAD_ID = waits.REQUESTING_THREAD_ID
                 JOIN performance_schema.threads blocking_thread ON blocking_thread.THREAD_ID = waits.BLOCKING_THREAD_ID
                 JOIN performance_schema.data_locks requesting_lock ON requesting_lock.ENGINE = waits.ENGINE AND requesting_lock.ENGINE_LOCK_ID = waits.REQUESTING_ENGINE_LOCK_ID
                 JOIN performance_schema.data_locks blocking_lock ON blocking_lock.ENGINE = waits.ENGINE AND blocking_lock.ENGINE_LOCK_ID = waits.BLOCKING_ENGINE_LOCK_ID
                 WHERE requesting_thread.PROCESSLIST_ID = ?
                   AND blocking_thread.PROCESSLIST_ID = ?
                   AND requesting_lock.OBJECT_SCHEMA = DATABASE()
                   AND requesting_lock.OBJECT_NAME = ?
                   AND requesting_lock.LOCK_STATUS = 'WAITING'
                   AND blocking_lock.OBJECT_SCHEMA = requesting_lock.OBJECT_SCHEMA
                   AND blocking_lock.OBJECT_NAME = requesting_lock.OBJECT_NAME
                   AND blocking_lock.LOCK_STATUS = 'GRANTED'",
                [$requestingConnectionId, $blockingConnectionId, $table]
            );

            if ((int) $wait->wait_count > 0) {
                return;
            }
            usleep(10_000);
        } while (microtime(true) < $deadline);

        $this->fail('Performance Schema did not observe the expected connection waiting on the held inventory lock.');
    }
}
