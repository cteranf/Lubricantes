<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\InventoryMovement;
use App\Models\InventoryReservation;
use App\Models\Order;
use App\Models\PaymentReceivingAccount;
use App\Models\PaymentSubmission;
use App\Models\PaymentTransaction;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseInventory;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Concerns\RequiresMySqlDriver;
use Tests\TestCase;

#[Group('mysql')]
class MySqlTransferInstallmentConcurrencyTest extends TestCase
{
    use RequiresMySqlDriver;

    public function test_concurrent_submissions_do_not_exceed_the_order_balance(): void
    {
        $this->requireDedicatedInnoDbDatabase();
        [$owner, $order] = $this->fixture('submit-race', '600.00');
        $account = $this->account();
        $results = $this->race([
            ['submit', '', $order->id, '', $owner->id, $account->id, '400.00', 'RACE-1001', 'submit-key-1001'],
            ['submit', '', $order->id, '', $owner->id, $account->id, '400.00', 'RACE-1002', 'submit-key-1002'],
        ]);

        $this->assertNotContains('unexpected_error', array_column($results, 'result'), json_encode($results));
        $this->assertSame(1, count(array_filter($results, fn ($result) => $result['result'] === 'submitted')));
        $this->assertSame(1, count(array_filter($results, fn ($result) => $result['result'] === 'controlled_error')));
        $submissions = PaymentSubmission::query()->where('order_id', $order->id)->get();
        $this->assertCount(1, $submissions);
        $this->assertLessThanOrEqual(60000, (int) round((float) $submissions->sum('amount') * 100));
        $this->assertSame('pending', $order->fresh()->payment_status);
        $this->assertSame(InventoryReservation::ACTIVE, InventoryReservation::query()->where('order_id', $order->id)->value('status'));
    }

    public function test_concurrent_final_approvals_consume_the_order_reservation_once(): void
    {
        $this->requireDedicatedInnoDbDatabase();
        [$owner, $order, $reservation, $inventory, $product] = $this->fixture('approval-race', '600.00', true);
        $treasury = User::factory()->create(['role' => 'treasury', 'is_active' => true]);
        $first = $this->submission($order, $owner, 'RACE-2001', '300.00');
        $second = $this->submission($order, $owner, 'RACE-2002', '300.00');
        $results = $this->race([
            ['approve', '', $order->id, $first->id, $treasury->id, '', '', '', ''],
            ['approve', '', $order->id, $second->id, $treasury->id, '', '', '', ''],
        ]);

        $this->assertNotContains('unexpected_error', array_column($results, 'result'), json_encode($results));
        $this->assertSame('approved', $order->fresh()->payment_status);
        $this->assertNotNull($order->fresh()->paid_at);
        $this->assertSame(2, PaymentTransaction::query()->where('order_id', $order->id)->where('status', PaymentTransaction::APPROVED)->count());
        $this->assertSame(1, PaymentTransaction::query()->where('order_id', $order->id)->where('approved_scope_key', PaymentTransaction::approvedScopeKeyForOrder($order->id))->count());
        $this->assertSame(1, InventoryReservation::query()->whereKey($reservation->id)->where('status', InventoryReservation::CONSUMED)->count());
        $this->assertSame(['quantity' => 1, 'reserved_quantity' => 0], $inventory->fresh()->only(['quantity', 'reserved_quantity']));
        $this->assertSame(1, (int) $product->fresh()->stock);
        $this->assertSame(1, InventoryMovement::query()->where('reference_type', 'order')->where('reference_id', (string) $order->id)->where('type', InventoryMovement::SALE)->count());
    }

    private function requireDedicatedInnoDbDatabase(): void
    {
        $connection = DB::connection();
        $database = getenv('LARAVEL13_MYSQL_TEST_DATABASE');
        if ($connection->getDriverName() !== 'mysql'
            || ! is_string($database)
            || preg_match('/\Alubristore_php85_laravel13_[a-z0-9_]+\z/', $database) !== 1
            || $connection->getDatabaseName() !== $database) {
            $this->markTestSkipped('Requires the dedicated disposable PHP 8.5/Laravel 13 MySQL schema.');
        }

        $engines = collect(DB::select(
            'SELECT LOWER(TABLE_NAME) AS table_name, ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND LOWER(TABLE_NAME) IN (?, ?, ?, ?, ?)',
            [$connection->getDatabaseName(), 'orders', 'payment_submissions', 'payment_transactions', 'inventory_reservations', 'warehouse_inventories'],
        ))->pluck('engine', 'table_name');
        foreach (['orders', 'payment_submissions', 'payment_transactions', 'inventory_reservations', 'warehouse_inventories'] as $table) {
            $this->assertSame('InnoDB', $engines->get($table), "{$table} must use InnoDB.");
        }
    }

    private function fixture(string $prefix, string $total, bool $withInventory = false): array
    {
        $suffix = strtoupper(substr(hash('sha256', uniqid($prefix, true)), 0, 12));
        $owner = User::factory()->create(['is_active' => true]);
        $branch = Branch::create(['code' => 'B-'.$suffix, 'name' => 'Abono QA', 'address' => 'Prueba aislada', 'is_active' => true]);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'code' => 'W-'.$suffix, 'name' => 'Abono QA', 'is_active' => true, 'is_default' => false]);
        $product = Product::create(['name' => 'Abono QA '.$suffix, 'slug' => 'abono-qa-'.$suffix, 'sku' => 'ABONO-'.$suffix, 'price' => $total, 'sale_price' => $total, 'stock' => 2, 'is_active' => true]);
        $inventory = WarehouseInventory::create(['warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'quantity' => 2, 'reserved_quantity' => 1]);
        $product->forceFill(['stock' => 2])->saveQuietly();
        $order = Order::create(['user_id' => $owner->id, 'status' => 'pending', 'total' => $total, 'shipping_info' => ['recipient_name' => 'QA'], 'payment_method' => 'transferencia', 'payment_status' => 'pending', 'reserved_until' => now()->addHour(), 'delivery_type' => 'delivery', 'tracking_status' => 'pending', 'fulfillment_status' => 'reserved']);
        $item = $order->items()->create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'quantity' => 1, 'price' => $total, 'subtotal' => $total]);
        $reservation = InventoryReservation::create(['order_id' => $order->id, 'order_item_id' => $item->id, 'product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'quantity' => 1, 'status' => InventoryReservation::ACTIVE, 'expires_at' => now()->addHour(), 'idempotency_key' => 'abono-res-'.$suffix]);

        return $withInventory ? [$owner, $order, $reservation, $inventory, $product] : [$owner, $order];
    }

    private function account(): PaymentReceivingAccount
    {
        return PaymentReceivingAccount::create(['code' => 'QA-'.strtoupper(substr(hash('sha256', uniqid('', true)), 0, 10)), 'channel' => 'bank_transfer', 'display_name' => 'Cuenta QA', 'holder_name' => 'QA', 'currency' => 'PEN', 'bank_name' => 'Banco QA', 'account_number' => '001122', 'is_active' => true, 'is_default' => true]);
    }

    private function submission(Order $order, User $owner, string $operation, string $amount): PaymentSubmission
    {
        return PaymentSubmission::create(['order_id' => $order->id, 'channel' => 'bank_transfer', 'status' => PaymentSubmission::PENDING_REVIEW, 'expected_amount' => $order->total, 'amount' => $amount, 'currency' => 'PEN', 'operation_number' => $operation, 'normalized_operation_number' => $operation, 'duplicate_fingerprint' => hash('sha256', $operation), 'declared_paid_at' => now()->subMinute(), 'origin_bank' => 'Banco QA', 'receiving_account_snapshot' => ['id' => 1], 'submitted_by' => $owner->id, 'submitted_at' => now(), 'idempotency_key' => 'submission-'.$operation]);
    }

    private function race(array $jobs): array
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'lubristore-installment-race-'.bin2hex(random_bytes(8));
        if (! mkdir($directory, 0700) && ! is_dir($directory)) {
            $this->fail('Could not create a temporary synchronization directory.');
        }
        $barrier = $directory.DIRECTORY_SEPARATOR.'start';
        $worker = base_path('tests/Support/mysql_transfer_installment_worker.php');
        $processes = [];
        $pipesByIndex = [];
        $readyFiles = [];

        try {
            foreach ($jobs as $index => $job) {
                $ready = $directory.DIRECTORY_SEPARATOR.'ready-'.$index;
                $readyFiles[] = $ready;
                $arguments = array_merge([PHP_BINARY, $worker, $job[0], $barrier, $ready], array_map('strval', array_slice($job, 2)));
                $pipes = [];
                $process = proc_open($arguments, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, base_path());
                if (! is_resource($process)) {
                    $this->fail('Could not start isolated MySQL race worker.');
                }
                fclose($pipes[0]);
                $processes[] = $process;
                $pipesByIndex[] = $pipes;
            }

            $deadline = microtime(true) + 20;
            while (count(array_filter($readyFiles, 'file_exists')) !== count($jobs) && microtime(true) < $deadline) {
                usleep(10_000);
            }
            $this->assertSame(count($jobs), count(array_filter($readyFiles, 'file_exists')), 'Both independent MySQL workers must reach the barrier.');
            touch($barrier);

            $results = [];
            foreach ($processes as $index => $process) {
                $stdout = stream_get_contents($pipesByIndex[$index][1]);
                $stderr = stream_get_contents($pipesByIndex[$index][2]);
                fclose($pipesByIndex[$index][1]);
                fclose($pipesByIndex[$index][2]);
                $this->assertSame('', trim($stderr));
                $this->assertSame(0, proc_close($process));
                $results[] = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
            }

            return $results;
        } finally {
            if (! file_exists($barrier)) {
                touch($barrier);
            }
            foreach ($processes as $index => $process) {
                if (is_resource($process)) {
                    proc_terminate($process);
                    foreach ([$pipesByIndex[$index][1] ?? null, $pipesByIndex[$index][2] ?? null] as $pipe) {
                        if (is_resource($pipe)) {
                            fclose($pipe);
                        }
                    }
                    proc_close($process);
                }
            }
            foreach (array_merge([$barrier], $readyFiles) as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }
}
