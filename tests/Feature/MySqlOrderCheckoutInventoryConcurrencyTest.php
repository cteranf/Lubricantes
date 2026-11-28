<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\InventoryReservation;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseInventory;
use App\Services\InventoryService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process;
use Tests\TestCase;

#[Group('mysql')]
class MySqlOrderCheckoutInventoryConcurrencyTest extends TestCase
{
    private string $directory;

    private string $connectionConfigFile;

    /** @var array<int, Process> */
    private array $workers = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Checkout stock contention requires MySQL/InnoDB.');
        }
        $database = getenv('LARAVEL13_MYSQL_TEST_DATABASE');
        $this->assertSame('lubristore_pos_foundation_20261125_test', $database);
        $this->assertSame('testing', app()->environment());
        $this->assertFalse((bool) config('app.debug'));
        $this->assertSame($database, config('database.connections.mysql.database'));
        $this->assertSame($database, DB::selectOne('SELECT DATABASE() AS name')->name);
        $this->assertSame('INNODB', strtoupper((string) DB::selectOne('SELECT @@default_storage_engine AS engine')->engine));
        // This suite may reset only the exact disposable schema above.
        Artisan::call('migrate:fresh', ['--force' => true]);

        $this->directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'order-checkout-race-'.bin2hex(random_bytes(8));
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
        parent::tearDown();
    }

    public function test_competing_web_checkouts_cannot_reserve_the_last_physical_unit(): void
    {
        $suffix = Str::lower(Str::random(8));
        // The legacy backfill migration creates the one globally default web warehouse.
        // Reuse it instead of violating warehouses_single_web_default_guard_unique.
        $warehouse = Warehouse::query()->where('is_default', true)->sole();
        $branch = Branch::query()->findOrFail($warehouse->branch_id);
        $branch->update(['is_active' => true, 'allows_pickup' => true, 'serves_public' => true]);
        $warehouse->update(['is_active' => true]);
        $product = Product::create(['name' => 'Last unit '.$suffix, 'slug' => 'last-unit-'.$suffix, 'sku' => 'CO-'.$suffix, 'price' => '15.00', 'stock' => 0, 'is_active' => true]);
        app(InventoryService::class)->initializeProduct($product, 1, $warehouse);
        $this->assertSame(1, (int) WarehouseInventory::where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->value('quantity'));
        $this->assertSame(0, (int) WarehouseInventory::where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->value('reserved_quantity'));

        $accounts = [];
        foreach (['one', 'two'] as $suffixUser) {
            $user = User::factory()->create(['role' => 'customer', 'is_active' => true]);
            $accounts[] = [
                'token' => $user->createToken('checkout-race')->plainTextToken,
                'payload' => [
                    'checkout_token' => (string) Str::uuid(), 'delivery_type' => 'pickup',
                    'pickup_branch_id' => $branch->id, 'payment_method' => 'transferencia',
                    'items' => [['product_id' => $product->id, 'quantity' => 1]],
                ],
            ];
        }
        $accountPath = $this->directory.DIRECTORY_SEPARATOR.'checkouts.json';
        file_put_contents($accountPath, json_encode($accounts, JSON_THROW_ON_ERROR), LOCK_EX);
        @chmod($accountPath, 0600);
        $observerConfig = DB::connection()->getConfig();
        config(['database.connections.checkout_race_observer' => $observerConfig]);
        $observer = DB::connection('checkout_race_observer');
        $observerId = (int) $observer->selectOne('SELECT CONNECTION_ID() AS id')->id;
        $this->assertSame('lubristore_pos_foundation_20261125_test', $observer->selectOne('SELECT DATABASE() AS name')->name);

        $first = $this->startWorker('hold-and-checkout', $product->id, $accountPath, 'checkout-a', '-', '0');
        $firstId = (int) $this->waitForFile('checkout-a.ready');
        $this->waitForFile('checkout-a.held');
        $second = $this->startWorker('checkout', $product->id, $accountPath, 'checkout-b', 'start', '1');
        $secondId = (int) $this->waitForFile('checkout-b.ready');
        $this->assertNotSame($firstId, $secondId);
        $this->assertNotSame($observerId, $firstId);
        $this->assertNotSame($observerId, $secondId);
        touch($this->path('start'));
        $this->waitForFile('checkout-b.started');
        $this->waitForLockWait($observer, $secondId, $firstId, 'branches');
        touch($this->path('release'));
        $first->wait();
        $second->wait();
        $firstResult = $this->readCheckoutResult($first, 'checkout-a');
        $secondResult = $this->readCheckoutResult($second, 'checkout-b');
        DB::purge('checkout_race_observer');

        $this->assertSame(201, $firstResult['status']);
        $this->assertSame(422, $secondResult['status']);
        $this->assertSame(1, DB::table('orders')->count());
        $order = DB::table('orders')->first();
        $this->assertSame('web', $order->sales_channel);
        $this->assertSame(1, DB::table('order_items')->where('order_id', $order->id)->count());
        $this->assertSame(1, InventoryReservation::where('order_id', $order->id)->where('status', InventoryReservation::ACTIVE)->count());
        $this->assertSame(1, (int) WarehouseInventory::where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->value('quantity'));
        $this->assertSame(1, (int) WarehouseInventory::where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->value('reserved_quantity'));
        $this->assertSame(1, (int) $product->fresh()->stock);
    }

    public function test_simultaneous_requests_with_same_checkout_token_create_one_order_and_reservation(): void
    {
        $suffix = Str::lower(Str::random(8));
        $warehouse = Warehouse::query()->where('is_default', true)->sole();
        $branch = Branch::query()->findOrFail($warehouse->branch_id);
        $branch->update(['is_active' => true, 'allows_pickup' => true, 'serves_public' => true]);
        $warehouse->update(['is_active' => true]);
        $product = Product::create(['name' => 'Idempotent unit '.$suffix, 'slug' => 'idempotent-unit-'.$suffix, 'sku' => 'ID-'.$suffix, 'price' => '15.00', 'stock' => 0, 'is_active' => true]);
        app(InventoryService::class)->initializeProduct($product, 5, $warehouse);
        $user = User::factory()->create(['role' => 'customer', 'is_active' => true]);
        $account = [
            'token' => $user->createToken('checkout-idempotency-race')->plainTextToken,
            'payload' => [
                'checkout_token' => (string) Str::uuid(), 'delivery_type' => 'pickup',
                'pickup_branch_id' => $branch->id, 'payment_method' => 'transferencia',
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
            ],
        ];
        $accountPath = $this->directory.DIRECTORY_SEPARATOR.'same-checkout.json';
        file_put_contents($accountPath, json_encode([$account, $account], JSON_THROW_ON_ERROR), LOCK_EX);
        @chmod($accountPath, 0600);

        config(['database.connections.checkout_race_observer' => DB::connection()->getConfig()]);
        $observer = DB::connection('checkout_race_observer');
        $observerId = (int) $observer->selectOne('SELECT CONNECTION_ID() AS id')->id;
        $this->assertSame('lubristore_pos_foundation_20261125_test', $observer->selectOne('SELECT DATABASE() AS name')->name);
        $this->assertSame(1, (int) $observer->selectOne('SELECT @@performance_schema AS enabled')->enabled);

        $first = $this->startWorker('hold-and-checkout', $product->id, $accountPath, 'same-a', '-', '0');
        $firstId = (int) $this->waitForFile('same-a.ready');
        $this->waitForFile('same-a.held');
        $second = $this->startWorker('checkout', $product->id, $accountPath, 'same-b', 'start', '1');
        $secondId = (int) $this->waitForFile('same-b.ready');
        $this->assertNotSame($firstId, $secondId);
        $this->assertNotSame($observerId, $firstId);
        $this->assertNotSame($observerId, $secondId);
        touch($this->path('start'));
        $this->waitForFile('same-b.started');
        $this->waitForLockWait($observer, $secondId, $firstId, 'branches');
        touch($this->path('release'));
        $first->wait();
        $second->wait();
        $firstResult = $this->readCheckoutResult($first, 'same-a');
        $secondResult = $this->readCheckoutResult($second, 'same-b');
        DB::purge('checkout_race_observer');

        $this->assertSame(201, $firstResult['status']);
        $this->assertSame(200, $secondResult['status']);
        $this->assertSame($firstResult['order_id'], $secondResult['order_id']);
        $this->assertSame(1, DB::table('orders')->where('checkout_token', $account['payload']['checkout_token'])->count());
        $orderId = DB::table('orders')->where('checkout_token', $account['payload']['checkout_token'])->value('id');
        $this->assertSame(1, DB::table('order_items')->where('order_id', $orderId)->count());
        $this->assertSame(1, InventoryReservation::where('order_id', $orderId)->where('status', InventoryReservation::ACTIVE)->count());
        $this->assertSame(1, (int) WarehouseInventory::where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->value('reserved_quantity'));
        $this->assertSame(5, (int) WarehouseInventory::where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->value('quantity'));
    }

    private function startWorker(string $mode, int $productId, string $accountsPath, string $label, string $start, string $accountIndex): Process
    {
        $process = new Process([
            PHP_BINARY, base_path('tests/Support/mysql_order_checkout_worker.php'), $this->connectionConfigFile,
            $mode, (string) $productId, $accountsPath, $label, $this->path($label.'.ready'), $this->path($label.'.held'),
            $this->path($label.'.started'), $start === '-' ? '-' : $this->path($start), $this->path('release'), $this->path($label.'.result'),
            $accountIndex,
        ], base_path(), null, null, 30);
        $process->start();
        $this->workers[] = $process;

        return $process;
    }

    private function readCheckoutResult(Process $process, string $label): array
    {
        $this->assertSame(0, $process->getExitCode(), 'Checkout race worker failed.');

        return json_decode($this->waitForFile($label.'.result'), true, flags: JSON_THROW_ON_ERROR);
    }

    private function waitForLockWait($observer, int $requesting, int $blocking, string $table): void
    {
        $deadline = microtime(true) + 10;
        do {
            $wait = $observer->selectOne(
                "SELECT COUNT(*) AS n FROM performance_schema.data_lock_waits w
                 JOIN performance_schema.threads r ON r.THREAD_ID = w.REQUESTING_THREAD_ID
                 JOIN performance_schema.threads b ON b.THREAD_ID = w.BLOCKING_THREAD_ID
                 JOIN performance_schema.data_locks l ON l.ENGINE = w.ENGINE AND l.ENGINE_LOCK_ID = w.REQUESTING_ENGINE_LOCK_ID
                 WHERE r.PROCESSLIST_ID = ? AND b.PROCESSLIST_ID = ? AND l.OBJECT_SCHEMA = DATABASE()
                   AND l.OBJECT_NAME = ? AND l.LOCK_STATUS = 'WAITING'",
                [$requesting, $blocking, $table]
            );
            if ((int) $wait->n > 0) {
                return;
            }
            usleep(10_000);
        } while (microtime(true) < $deadline);
        $this->fail('The checkout worker did not wait on the canonical warehouse lock.');
    }

    private function waitForFile(string $name): string
    {
        $path = $this->path($name);
        $deadline = microtime(true) + 20;
        while (! is_file($path) && microtime(true) < $deadline) {
            usleep(20_000);
        }
        $this->assertFileExists($path, 'A checkout worker did not reach its synchronization point.');

        return trim((string) file_get_contents($path));
    }

    private function path(string $name): string
    {
        return $this->directory.DIRECTORY_SEPARATOR.$name;
    }
}
