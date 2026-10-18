<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\InventoryMovement;
use App\Models\InventoryReservation;
use App\Models\Order;
use App\Models\OrderFulfillmentHistory;
use App\Models\OrderItem;
use App\Models\PaymentRefund;
use App\Models\PaymentSubmission;
use App\Models\PaymentSubmissionHistory;
use App\Models\PaymentTransaction;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseInventory;
use App\Services\TreasuryPaymentRefundService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class MySqlTreasuryPaymentRefundConcurrencyTest extends TestCase
{
    private const DATABASE = 'lubristore_2b4_resolution_certification_test';

    public function test_real_mysql_deadlock_retries_the_complete_mark_pending_transaction(): void
    {
        $this->requireIsolatedMysql();
        $fixtures = [];
        $workers = [];
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'lubristore-refund-race-'.bin2hex(random_bytes(8));
        $firstReady = $directory.DIRECTORY_SEPARATOR.'ready-first';
        $secondReady = $directory.DIRECTORY_SEPARATOR.'ready-second';
        $release = $directory.DIRECTORY_SEPARATOR.'release';

        try {
            $this->assertTrue(mkdir($directory));
            $fixtures['first'] = $this->fixture('first');
            $fixtures['second'] = $this->fixture('second');
            $workerPath = base_path('tests/Support/mysql_treasury_refund_worker.php');
            $this->assertSame('mysql_treasury_refund_worker.php', basename($workerPath));

            $workers['first'] = $this->startWorker($workerPath, [
                $fixtures['first']['order']->id,
                $fixtures['first']['treasury']->id,
                $fixtures['second']['order']->id,
                $firstReady,
                $release,
                'first',
                $directory,
                'mark_pending_deadlock',
            ]);
            $workers['second'] = $this->startWorker($workerPath, [
                $fixtures['second']['order']->id,
                $fixtures['second']['treasury']->id,
                $fixtures['first']['order']->id,
                $secondReady,
                $release,
                'second',
                $directory,
                'mark_pending_deadlock',
            ]);

            $this->waitForReady($workers, $firstReady, $secondReady);
            $this->assertSame(7, file_put_contents($release, 'release', LOCK_EX));
            $results = $this->waitForResults($workers);
            $summary = $this->safeSummary($results);

            foreach ($results as $result) {
                $this->assertSame(0, $result['exit_code'], $summary);
                $this->assertSame('success', $result['json']['result'] ?? null, $summary);
                $this->assertTrue((bool) ($result['json']['ok'] ?? false), $summary);
                $this->assertSame(PaymentRefund::PENDING, $result['json']['status'] ?? null, $summary);
                $this->assertTrue((bool) ($result['json']['fresh_hydration'] ?? false), $summary);
                $this->assertTrue((bool) ($result['json']['release_observed'] ?? false), $summary);
            }

            $attempts = collect($results)->pluck('json.attempts')->map(fn ($attempt) => (int) $attempt)->sort()->values()->all();
            $this->assertSame([1, 2], $attempts, 'Exactly one physical deadlock victim must retry once.');
            $this->assertSame(1, collect($results)->filter(fn ($result) => ($result['json']['retry_observed'] ?? false) === true)->count(), 'Exactly one worker must report the real retry.');
            $victim = collect($results)->sole(fn ($result) => ($result['json']['retry_observed'] ?? false) === true);
            $winner = collect($results)->sole(fn ($result) => ($result['json']['retry_observed'] ?? false) !== true);
            $this->assertSame([1, 2], $victim['json']['attempt_numbers'] ?? null, 'The deadlock victim must report both attempts in the same worker.');
            $this->assertSame([1], $winner['json']['attempt_numbers'] ?? null, 'The winning worker must complete in its first attempt.');
            $this->assertCount(1, $victim['json']['concurrency_failures'] ?? [], 'The victim must observe exactly one absorbed concurrency failure.');
            $this->assertCount(0, $winner['json']['concurrency_failures'] ?? [], 'The winner must not hide a concurrency failure.');
            $absorbedFailure = $victim['json']['concurrency_failures'][0] ?? [];
            $this->assertSame('40001', $absorbedFailure['sqlstate'] ?? null, 'The absorbed failure must be an InnoDB deadlock SQLSTATE.');
            $this->assertSame(1213, $absorbedFailure['driver_code'] ?? null, 'The absorbed failure must be the MySQL deadlock code.');
            $this->assertNotSame(1205, $absorbedFailure['driver_code'] ?? null, 'A lock-wait timeout must not satisfy deadlock certification.');

            foreach ($fixtures as $fixture) {
                $refunds = PaymentRefund::query()->where('original_payment_transaction_id', $fixture['transaction']->id)->get();
                $this->assertCount(1, $refunds, 'Each payment must have exactly one pending refund.');
                $this->assertSame(PaymentRefund::PENDING, $refunds->first()->status);
                $this->assertSame('refund_pending', $fixture['order']->fresh()->payment_status);
                $this->assertSame(PaymentSubmission::REFUND_PENDING, $fixture['submission']->fresh()->status);
                $histories = PaymentSubmissionHistory::query()
                    ->where('payment_submission_id', $fixture['submission']->id)
                    ->where('event', PaymentSubmissionHistory::REFUND_MARKED_PENDING)
                    ->get();
                $this->assertCount(1, $histories, 'Each submission must have exactly one refund history event.');
                $this->assertSame(1, InventoryReservation::where('order_id', $fixture['order']->id)->count());
                $this->assertSame(2, InventoryMovement::where('reference_type', 'order')->where('reference_id', (string) $fixture['order']->id)->count());
                $this->assertSame(12, (int) $fixture['inventory']->fresh()->quantity);
                $this->assertSame(0, (int) $fixture['inventory']->fresh()->reserved_quantity);
                $this->assertSame(12, (int) $fixture['product']->fresh()->stock);
            }
        } finally {
            $this->terminateWorkers($workers);
            foreach ([$firstReady, $secondReady, $release, $directory.DIRECTORY_SEPARATOR.'deadlock-first', $directory.DIRECTORY_SEPARATOR.'deadlock-second'] as $path) {
                if (is_file($path)) {
                    @unlink($path);
                }
            }
            if (is_dir($directory)) {
                @rmdir($directory);
            }
            foreach (array_reverse($fixtures) as $fixture) {
                $this->cleanupFixture($fixture);
            }
        }

        $this->assertDirectoryDoesNotExist($directory);
    }

    public function test_snapshot_pending_returns_the_locked_completed_instance(): void
    {
        $this->test_physical_completion_races_preserve_atomicity_and_privacy('complete_snapshot', true);
    }

    /** @dataProvider completionRaces */
    public function test_physical_completion_races_preserve_atomicity_and_privacy(string $action, bool $withSubmission): void
    {
        $this->requireIsolatedMysql();
        for ($iteration = 0; $iteration < 10; $iteration++) {
            $fixtures = [];
            $workers = [];
            $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'lubristore-refund-completion-'.bin2hex(random_bytes(8));
            $readyFirst = $directory.DIRECTORY_SEPARATOR.'ready-first';
            $readySecond = $directory.DIRECTORY_SEPARATOR.'ready-second';
            $release = $directory.DIRECTORY_SEPARATOR.'release';
            try {
                $this->assertTrue(mkdir($directory));
                $fixtures['first'] = $this->fixture('completion-first', true);
                $fixtures['second'] = $this->fixture('completion-second', true);
                foreach ($fixtures as &$fixture) {
                    if (! $withSubmission) {
                        DB::table('payment_transactions')->where('id', $fixture['transaction']->id)->update(['payment_submission_id' => null]);
                        DB::table('payment_submissions')->where('id', $fixture['submission']->id)->delete();
                        $fixture['submission'] = null;
                    }
                    $fixture['refund'] = app(TreasuryPaymentRefundService::class)->markPending($fixture['order'], $fixture['treasury'], 'Solicitud de certificación física');
                    $fixture['protected_digest'] = $this->protectedFixtureDigest($fixture);
                }
                unset($fixture);
                $deadlock = $action === 'complete_deadlock';
                $workerPath = base_path('tests/Support/mysql_treasury_refund_worker.php');
                $workers['first'] = $this->startWorker($workerPath, [
                    $fixtures['first']['order']->id, $fixtures['first']['treasury']->id,
                    $fixtures['second']['order']->id, $readyFirst, $release, 'first', $directory, $action,
                ]);
                $workers['second'] = $this->startWorker($workerPath, [
                    $fixtures[$deadlock ? 'second' : 'first']['order']->id, $fixtures['second']['treasury']->id,
                    $fixtures['first']['order']->id, $readySecond, $release, 'second', $directory, $action,
                ]);
                $this->waitForReady($workers, $readyFirst, $readySecond);
                $this->assertSame(7, file_put_contents($release, 'release', LOCK_EX));
                $results = $this->waitForResults($workers);
                $summary = $this->safeSummary($results);
                if ($action === 'complete_snapshot') {
                    $this->assertTrue($results['first']['json']['snapshot_pending'] ?? false, $summary);
                    $this->assertTrue($results['first']['json']['locked_completed'] ?? false, $summary);
                    $this->assertSame($fixtures['second']['treasury']->id, $results['first']['json']['completed_by'] ?? null, $summary);
                }
                foreach ($results as $result) {
                    $this->assertSame(0, $result['exit_code'], $summary);
                    $this->assertFalse($result['stderr_present'], 'Workers must not emit private diagnostics.');
                }
                $successes = collect($results)->filter(fn ($result) => ($result['json']['result'] ?? null) === 'success');
                $conflicts = collect($results)->filter(fn ($result) => ($result['json']['result'] ?? null) === 'conflict');
                $this->assertCount($action === 'complete_different' ? 1 : 2, $successes, $summary);
                $this->assertCount($action === 'complete_different' ? 1 : 0, $conflicts, $summary);
                foreach ($successes as $result) {
                    $this->assertTrue($result['json']['same_locked_instance'] ?? false, $summary);
                    $this->assertSame(PaymentRefund::COMPLETED, $result['json']['status'] ?? null, $summary);
                    $this->assertTrue($result['json']['fresh_hydration'] ?? false, $summary);
                    $this->assertTrue($result['json']['release_observed'] ?? false, $summary);
                }
                if (in_array($action, ['complete_same', 'complete_snapshot'], true)) {
                    $this->assertSame(1, $successes->pluck('json.refund_id')->unique()->count());
                    $this->assertSame(1, $successes->pluck('json.accounting_id')->unique()->count());
                    $this->assertSame(1, $successes->pluck('json.completed_by')->unique()->count());
                }
                if ($deadlock) {
                    $this->assertSame([1, 2], collect($results)->pluck('json.attempts')->sort()->values()->all(), $summary);
                    $victim = collect($results)->sole(fn ($result) => ($result['json']['retry_observed'] ?? false) === true);
                    $winner = collect($results)->sole(fn ($result) => ($result['json']['retry_observed'] ?? false) === false);
                    $this->assertSame([1, 2], $victim['json']['attempt_numbers']);
                    $this->assertSame([1], $winner['json']['attempt_numbers']);
                    $this->assertCount(0, $winner['json']['concurrency_failures']);
                    $this->assertCount(1, $victim['json']['concurrency_failures']);
                    $failure = $victim['json']['concurrency_failures'][0];
                    $this->assertSame('40001', $failure['sqlstate']);
                    $this->assertSame(1213, $failure['driver_code']);
                    $this->assertNotSame(1205, $failure['driver_code']);
                }
                foreach ($fixtures as $name => $fixture) {
                    $completed = $deadlock || $name === 'first';
                    $refund = $fixture['refund']->fresh();
                    $this->assertSame($completed ? PaymentRefund::COMPLETED : PaymentRefund::PENDING, $refund->status);
                    $this->assertSame($completed ? 'refunded' : 'refund_pending', $fixture['order']->fresh()->payment_status);
                    $this->assertSame($completed ? 1 : 0, PaymentTransaction::where('order_id', $fixture['order']->id)->where('transaction_type', PaymentTransaction::REFUND)->count());
                    $this->assertSame($withSubmission ? 1 : 0, PaymentSubmission::where('order_id', $fixture['order']->id)->count());
                    $submissionId = $fixture['submission']?->id;
                    $this->assertSame($withSubmission && $completed ? 1 : 0, PaymentSubmissionHistory::where('payment_submission_id', $submissionId)->where('event', PaymentSubmissionHistory::REFUND_COMPLETED)->count());
                    if ($withSubmission) {
                        $this->assertSame($completed ? PaymentSubmission::REFUNDED : PaymentSubmission::REFUND_PENDING, $fixture['submission']->fresh()->status);
                    }
                    $this->assertTrue(hash_equals($fixture['protected_digest'], $this->protectedFixtureDigest($fixture)), 'Inventory, logistics and original payment must remain unchanged.');
                    if ($completed) {
                        $returned = $successes->filter(fn ($result) => (int) $result['json']['refund_id'] === (int) $refund->id);
                        $this->assertNotEmpty($returned, $summary);
                        foreach ($returned as $result) {
                            $this->assertSame((int) $refund->completed_by, $result['json']['completed_by'], $summary);
                            $this->assertSame((int) $refund->refund_payment_transaction_id, $result['json']['accounting_id'], $summary);
                        }
                        $expectedReference = 'certification-fixture';
                        if ($action === 'complete_different') {
                            $expectedReference .= (int) $refund->completed_by === (int) $fixtures['first']['treasury']->id ? '-first' : '-second';
                        }
                        $this->assertTrue(hash_equals($expectedReference, $refund->external_reference), 'The persisted reference must match the winning completion.');
                        if ($withSubmission) {
                            $history = PaymentSubmissionHistory::where('payment_submission_id', $submissionId)->where('event', PaymentSubmissionHistory::REFUND_COMPLETED)->sole();
                            $this->assertSame(PaymentSubmission::REFUND_PENDING, $history->from_status);
                            $this->assertSame(PaymentSubmission::REFUNDED, $history->to_status);
                            $this->assertSame((int) $refund->completed_by, (int) $history->actor_id);
                            $this->assertSame('total_refund_completed', $history->reason);
                            $this->assertSame(['amount' => '50.00', 'currency' => 'PEN'], $history->safe_metadata);
                            $this->assertTrue($refund->completed_at->equalTo($history->occurred_at));
                        }
                        $this->assertSame((int) $refund->refund_payment_transaction_id, (int) $refund->refundPaymentTransaction->id);
                        $this->assertSame($refund->completed_by, (int) $refund->refundPaymentTransaction->confirmed_by);
                        $this->assertTrue($refund->completed_at->equalTo($refund->refundPaymentTransaction->confirmed_at));
                        $this->assertArrayNotHasKey('external_reference', $refund->toArray());
                        $cipher = DB::table('payment_refunds')->where('id', $refund->id)->value('external_reference');
                        $this->assertFalse(str_contains($cipher, 'certification-fixture'), 'References must remain encrypted.');
                    }
                }
                if ($iteration === 9) {
                    fwrite(STDOUT, PHP_EOL.'Safe worker summary: '.$summary.PHP_EOL);
                }
            } finally {
                $this->terminateWorkers($workers);
                foreach ([$readyFirst, $readySecond, $release, $directory.DIRECTORY_SEPARATOR.'deadlock-first', $directory.DIRECTORY_SEPARATOR.'deadlock-second', $directory.DIRECTORY_SEPARATOR.'snapshot-pending', $directory.DIRECTORY_SEPARATOR.'completion-committed'] as $path) {
                    if (is_file($path)) {
                        @unlink($path);
                    }
                }
                if (is_dir($directory)) {
                    @rmdir($directory);
                }
                foreach (array_reverse($fixtures) as $fixture) {
                    $this->cleanupFixture($fixture);
                }
            }
            $this->assertDirectoryDoesNotExist($directory);
        }
    }

    public static function completionRaces(): array
    {
        return [
            'same with submission' => ['complete_same', true],
            'different with submission' => ['complete_different', true],
            'deadlock with submission' => ['complete_deadlock', true],
            'same without submission' => ['complete_same', false],
            'different without submission' => ['complete_different', false],
            'deadlock without submission' => ['complete_deadlock', false],
        ];
    }

    private function protectedFixtureDigest(array $fixture): string
    {
        $orderId = $fixture['order']->id;
        $parts = [];
        foreach (['order_items', 'inventory_reservations', 'order_fulfillment_histories', 'order_handling_processes', 'order_handling_incidents', 'order_handling_histories', 'order_deliveries', 'order_delivery_histories'] as $table) {
            $parts[$table] = DB::table($table)->where('order_id', $orderId)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }
        $parts['movements'] = DB::table('inventory_movements')->where('reference_type', 'order')->where('reference_id', (string) $orderId)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        $parts['handling_items'] = DB::table('order_handling_items')->where('order_item_id', $fixture['item']->id)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        $parts['delivery_attempts'] = DB::table('order_delivery_attempts')->whereIn('order_delivery_id', array_column($parts['order_deliveries'], 'id'))->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        $parts['inventory'] = $fixture['inventory']->fresh()->getAttributes();
        $parts['product'] = $fixture['product']->fresh()->getAttributes();
        $parts['payment'] = $fixture['transaction']->fresh()->getAttributes();
        $parts['order'] = $fixture['order']->fresh()->getAttributes();
        unset($parts['order']['payment_status'], $parts['order']['updated_at']);
        $parts['foreign'] = array_map(fn ($model) => $model->fresh()->getAttributes(), $fixture['foreign'] ?? []);

        return hash('sha256', json_encode($this->canonicalSnapshot($parts), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }

    private function canonicalSnapshot(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn ($item) => $this->canonicalSnapshot($item), $value);
    }

    private function requireIsolatedMysql(): void
    {
        // Check the configured destination before the first connection.
        $this->assertSame('testing', app()->environment());
        $this->assertFalse((bool) config('app.debug'));
        $this->assertSame('mysql', config('database.default'));
        $this->assertSame(self::DATABASE, config('database.connections.mysql.database'));
        $this->assertEmpty(config('database.connections.mysql.url'));
        $database = DB::selectOne('SELECT DATABASE() AS name')->name ?? null;
        $version = DB::selectOne('SELECT VERSION() AS version')->version ?? '';
        $this->assertSame('testing', app()->environment());
        $this->assertFalse((bool) config('app.debug'));
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame(self::DATABASE, $database);
        $this->assertTrue(str_ends_with((string) $database, '_test'));
        $this->assertNotSame('lubricantes', $database);
        $this->assertTrue(str_starts_with((string) $version, '8.'));
        foreach (['2026_09_26_070600_create_payment_refunds_table', '2026_09_26_070620_add_refund_pending_to_order_payment_status', '2026_10_11_070630_generalize_payment_refunds_for_total_refunds'] as $migration) {
            $this->assertSame(1, DB::table('migrations')->where('migration', $migration)->count());
        }
    }

    private function startWorker(string $workerPath, array $arguments): array
    {
        $environment = array_merge(getenv() ?: [], [
            'APP_ENV' => 'testing',
            'APP_DEBUG' => 'false',
            'DB_CONNECTION' => 'mysql',
            'DB_DATABASE' => self::DATABASE,
        ]);
        $command = array_merge([PHP_BINARY, $workerPath], array_map(static fn ($argument) => (string) $argument, $arguments));
        $process = proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, base_path(), $environment);
        $this->assertIsResource($process);
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        return [
            'process' => $process,
            'stdout_pipe' => $pipes[1],
            'stderr_pipe' => $pipes[2],
            'stdout' => '',
            'stderr' => '',
            'exit_code' => null,
            'closed' => false,
        ];
    }

    private function waitForReady(array &$workers, string $firstReady, string $secondReady): void
    {
        $deadline = microtime(true) + 10;
        while (microtime(true) < $deadline) {
            clearstatcache(true, $firstReady);
            clearstatcache(true, $secondReady);
            if (@file_get_contents($firstReady) !== false && @file_get_contents($secondReady) !== false) {
                return;
            }
            foreach ($workers as &$worker) {
                $status = proc_get_status($worker['process']);
                $worker['running'] = (bool) $status['running'];
                if (! $worker['running']) {
                    if ((int) $status['exitcode'] >= 0) {
                        $worker['exit_code'] = (int) $status['exitcode'];
                    }
                    $worker['stdout'] .= stream_get_contents($worker['stdout_pipe']) ?: '';
                    $worker['stderr'] .= stream_get_contents($worker['stderr_pipe']) ?: '';
                    $decoded = json_decode(trim($worker['stdout']), true);
                    $diagnostic = json_encode([
                        'exit_code' => $worker['exit_code'],
                        'result' => is_array($decoded) ? ($decoded['result'] ?? null) : 'invalid_json',
                        'stage' => is_array($decoded) ? ($decoded['stage'] ?? null) : null,
                        'stderr_present' => trim($worker['stderr']) !== '',
                    ], JSON_THROW_ON_ERROR);
                    $this->fail('A refund worker exited before reaching the barrier: '.$diagnostic);
                }
            }
            unset($worker);
            usleep(20_000);
        }

        $this->fail('Refund workers did not reach the ready barrier.');
    }

    private function waitForResults(array &$workers): array
    {
        $deadline = microtime(true) + 40;
        while (microtime(true) < $deadline) {
            $running = false;
            foreach ($workers as &$worker) {
                $this->pollWorker($worker);
                $running = $running || $worker['running'];
            }
            unset($worker);
            if (! $running) {
                break;
            }
            usleep(20_000);
        }

        $results = [];
        foreach ($workers as $name => &$worker) {
            $this->pollWorker($worker);
            $this->assertFalse($worker['running'], 'A refund worker exceeded the execution deadline.');
            $worker['stdout'] .= stream_get_contents($worker['stdout_pipe']) ?: '';
            $worker['stderr'] .= stream_get_contents($worker['stderr_pipe']) ?: '';
            fclose($worker['stdout_pipe']);
            fclose($worker['stderr_pipe']);
            $closeCode = proc_close($worker['process']);
            $worker['closed'] = true;
            $decoded = json_decode(trim($worker['stdout']), true);
            $this->assertIsArray($decoded, 'A refund worker returned invalid JSON.');
            $results[$name] = [
                'exit_code' => $worker['exit_code'] ?? $closeCode,
                'json' => $decoded,
                'stderr_present' => trim($worker['stderr']) !== '',
            ];
        }
        unset($worker);

        return $results;
    }

    private function pollWorker(array &$worker): void
    {
        $worker['stdout'] .= stream_get_contents($worker['stdout_pipe']) ?: '';
        $worker['stderr'] .= stream_get_contents($worker['stderr_pipe']) ?: '';
        $status = proc_get_status($worker['process']);
        $worker['running'] = (bool) $status['running'];
        if (! $worker['running'] && (int) $status['exitcode'] >= 0) {
            $worker['exit_code'] = (int) $status['exitcode'];
        }
    }

    private function terminateWorkers(array &$workers): void
    {
        foreach ($workers as &$worker) {
            if ($worker['closed']) {
                continue;
            }
            $status = proc_get_status($worker['process']);
            if ($status['running']) {
                @proc_terminate($worker['process']);
            }
            @fclose($worker['stdout_pipe']);
            @fclose($worker['stderr_pipe']);
            @proc_close($worker['process']);
            $worker['closed'] = true;
        }
        unset($worker);
    }

    private function safeSummary(array $results): string
    {
        return json_encode(collect($results)->map(fn ($result) => [
            'exit_code' => $result['exit_code'],
            'result' => $result['json']['result'] ?? null,
            'status' => $result['json']['status'] ?? null,
            'same_locked_instance' => $result['json']['same_locked_instance'] ?? null,
            'snapshot_pending' => $result['json']['snapshot_pending'] ?? null,
            'locked_completed' => $result['json']['locked_completed'] ?? null,
            'ok' => $result['json']['ok'] ?? null,
            'attempts' => $result['json']['attempts'] ?? null,
            'retry_observed' => $result['json']['retry_observed'] ?? null,
            'exception_class' => $result['json']['exception_class'] ?? null,
            'previous_exception_class' => $result['json']['previous_exception_class'] ?? null,
            'stage' => $result['json']['stage'] ?? null,
            'sqlstate' => $result['json']['sqlstate'] ?? null,
            'driver_code' => $result['json']['driver_code'] ?? null,
            'diagnostic' => $result['json']['diagnostic'] ?? null,
        ])->all(), JSON_THROW_ON_ERROR);
    }

    private function fixture(string $label, bool $withGlobalReservation = false): array
    {
        $suffix = $label.'-'.Str::lower(Str::random(12));
        $treasury = User::factory()->create(['role' => 'treasury', 'is_active' => true, 'email' => "refund-treasury-{$suffix}@example.test"]);
        $customer = User::factory()->create(['role' => 'customer', 'is_active' => true, 'email' => "refund-customer-{$suffix}@example.test"]);
        $branch = Branch::create(['code' => 'RFD-'.$suffix, 'name' => 'Refund deadlock branch', 'address' => 'Test', 'is_active' => true]);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'code' => 'RFW-'.$suffix, 'name' => 'Refund deadlock warehouse', 'is_active' => true]);
        $product = Product::create(['name' => 'Refund deadlock '.$suffix, 'slug' => 'refund-deadlock-'.$suffix, 'sku' => 'RFD-'.$suffix, 'price' => '25.00', 'is_active' => true]);
        $product->forceFill(['stock' => 12])->save();
        $inventory = WarehouseInventory::create(['warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'quantity' => 12, 'reserved_quantity' => 0]);
        $order = Order::create([
            'user_id' => $customer->id,
            'status' => 'canceled',
            'total' => '50.00',
            'shipping_info' => ['address' => 'Test'],
            'payment_method' => 'transferencia',
            'payment_status' => 'approved',
            'paid_at' => now()->subHour(),
            'tracking_status' => 'canceled',
            'fulfillment_status' => Order::FULFILLMENT_CANCELED,
        ]);
        $item = OrderItem::forceCreate(['order_id' => $order->id, 'product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'quantity' => 2, 'price' => '25.00', 'subtotal' => '50.00']);
        $reservation = InventoryReservation::forceCreate([
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 2,
            'reservation_sequence' => 1,
            'resolution_case_id' => null,
            'status' => InventoryReservation::CONSUMED,
            'expires_at' => now()->subDay(),
            'consumed_at' => now()->subHours(2),
            'released_at' => null,
            'idempotency_key' => 'refund-deadlock-reservation-'.$suffix,
        ]);
        $submission = PaymentSubmission::create([
            'order_id' => $order->id,
            'channel' => 'bank_transfer',
            'status' => PaymentSubmission::APPROVED,
            'expected_amount' => '50.00',
            'currency' => 'PEN',
            'operation_number' => 'RFD-'.$suffix,
            'normalized_operation_number' => 'RFD'.str_replace('-', '', $suffix),
            'duplicate_fingerprint' => hash('sha256', 'refund-deadlock-'.$suffix),
            'declared_paid_at' => now()->subHours(3),
            'receiving_account_snapshot' => ['channel' => 'bank_transfer'],
            'submitted_by' => $customer->id,
            'submitted_at' => now()->subHours(3),
            'reviewed_by' => $treasury->id,
            'reviewed_at' => now()->subHours(2),
            'decision_reason' => 'Aprobado',
            'idempotency_key' => 'refund-deadlock-submission-'.$suffix,
        ]);
        $transaction = PaymentTransaction::forceCreate([
            'order_id' => $order->id,
            'payment_submission_id' => $submission->id,
            'gateway' => 'manual',
            'payment_method' => 'transferencia',
            'transaction_type' => PaymentTransaction::PAYMENT,
            'status' => PaymentTransaction::APPROVED,
            'amount' => '50.00',
            'currency' => 'PEN',
            'idempotency_key' => 'refund-deadlock-payment-'.$suffix,
            'approved_scope_key' => PaymentTransaction::approvedScopeKeyForOrder($order->id),
            'confirmed_by' => $treasury->id,
            'confirmed_at' => now()->subHours(2),
        ]);
        $sale = InventoryMovement::create([
            'warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'user_id' => $treasury->id,
            'type' => InventoryMovement::SALE, 'quantity' => 2, 'quantity_before' => 12, 'quantity_after' => 10,
            'reason' => 'Venta', 'reference_type' => 'order', 'reference_id' => (string) $order->id,
            'idempotency_key' => 'sale-order-item-'.$item->id, 'created_at' => now()->subHours(2),
        ]);
        $return = InventoryMovement::create([
            'warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'user_id' => $treasury->id,
            'type' => InventoryMovement::CANCELLATION_RETURN, 'quantity' => 2, 'quantity_before' => 10, 'quantity_after' => 12,
            'reason' => 'Reposición', 'reference_type' => 'order', 'reference_id' => (string) $order->id,
            'idempotency_key' => 'cancellation-order-item-'.$item->id, 'created_at' => now()->subHour(),
        ]);
        $fulfillmentHistory = OrderFulfillmentHistory::create([
            'order_id' => $order->id,
            'from_status' => Order::FULFILLMENT_RESERVED,
            'to_status' => Order::FULFILLMENT_CANCELED,
            'user_id' => $treasury->id,
            'observation' => 'Cancelación manual',
            'metadata' => ['inventory_was_consumed' => true],
            'idempotency_key' => 'fulfillment-order-'.$order->id.'-canceled',
            'created_at' => now()->subHour(),
        ]);

        $foreign = [];
        if ($withGlobalReservation) {
            $foreign['warehouse'] = Warehouse::create(['branch_id' => $branch->id, 'code' => 'RFG-'.$suffix, 'name' => 'Foreign reservation fixture', 'is_active' => true]);
            $foreign['balance'] = WarehouseInventory::create(['warehouse_id' => $foreign['warehouse']->id, 'product_id' => $product->id, 'quantity' => 5, 'reserved_quantity' => 2]);
            $product->forceFill(['stock' => 17])->save();
            $foreign['order'] = Order::create(['user_id' => $customer->id, 'status' => 'pending', 'total' => '50.00', 'shipping_info' => ['address' => 'Fixture'], 'payment_method' => 'transferencia', 'payment_status' => 'pending', 'fulfillment_status' => Order::FULFILLMENT_RESERVED]);
            $foreign['item'] = OrderItem::forceCreate(['order_id' => $foreign['order']->id, 'product_id' => $product->id, 'warehouse_id' => $foreign['warehouse']->id, 'quantity' => 2, 'price' => '25.00', 'subtotal' => '50.00']);
            $foreign['reservation'] = InventoryReservation::forceCreate(['order_id' => $foreign['order']->id, 'order_item_id' => $foreign['item']->id, 'product_id' => $product->id, 'warehouse_id' => $foreign['warehouse']->id, 'quantity' => 2, 'reservation_sequence' => 1, 'status' => InventoryReservation::ACTIVE, 'expires_at' => now()->addHour(), 'idempotency_key' => 'refund-global-'.$suffix]);
        }

        return compact('treasury', 'customer', 'branch', 'warehouse', 'product', 'inventory', 'order', 'item', 'reservation', 'submission', 'transaction', 'sale', 'return', 'fulfillmentHistory', 'foreign');
    }

    private function cleanupFixture(array $fixture): void
    {
        if ($foreign = $fixture['foreign'] ?? []) {
            DB::table('inventory_reservations')->where('id', $foreign['reservation']->id)->delete();
            DB::table('order_items')->where('id', $foreign['item']->id)->delete();
            DB::table('orders')->where('id', $foreign['order']->id)->delete();
            DB::table('warehouse_inventories')->where('id', $foreign['balance']->id)->delete();
            DB::table('warehouses')->where('id', $foreign['warehouse']->id)->delete();
        }
        $orderId = (int) $fixture['order']->id;
        $submissionId = $fixture['submission']?->id;
        if ($submissionId !== null) {
            DB::table('payment_submission_histories')->where('payment_submission_id', $submissionId)->delete();
        }
        DB::table('payment_refunds')->where('original_payment_transaction_id', $fixture['transaction']->id)->delete();
        DB::table('inventory_movements')->where('reference_type', 'order')->where('reference_id', (string) $orderId)->delete();
        DB::table('order_fulfillment_histories')->where('order_id', $orderId)->delete();
        DB::table('inventory_reservations')->where('order_id', $orderId)->delete();
        DB::table('payment_transactions')->where('order_id', $orderId)->delete();
        if ($submissionId !== null) {
            DB::table('payment_submissions')->where('id', $submissionId)->delete();
        }
        DB::table('order_items')->where('order_id', $orderId)->delete();
        DB::table('orders')->where('id', $orderId)->delete();
        DB::table('warehouse_inventories')->where('id', $fixture['inventory']->id)->delete();
        DB::table('products')->where('id', $fixture['product']->id)->delete();
        DB::table('warehouses')->where('id', $fixture['warehouse']->id)->delete();
        DB::table('branches')->where('id', $fixture['branch']->id)->delete();
        DB::table('users')->whereIn('id', [$fixture['treasury']->id, $fixture['customer']->id])->delete();
    }
}
