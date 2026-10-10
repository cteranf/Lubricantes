<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\InventoryMovement;
use App\Models\InventoryReservation;
use App\Models\Order;
use App\Models\PaymentResolutionCase;
use App\Models\PaymentResolutionHistory;
use App\Models\PaymentSubmission;
use App\Models\PaymentSubmissionHistory;
use App\Models\PaymentTransaction;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseInventory;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MySqlTreasuryPaymentResolutionConcurrencyTest extends TestCase
{
    public function test_resolution_barrier_paths_are_consistent_without_a_database(): void
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'lubristore-resolution-race-'.uniqid('', true);
        $this->assertTrue(mkdir($directory) || is_dir($directory));
        $firstReady = $directory.DIRECTORY_SEPARATOR.'ready-first';
        $secondReady = $directory.DIRECTORY_SEPARATOR.'ready-second';
        $release = $directory.DIRECTORY_SEPARATOR.'release';
        $probe = base_path('tests/Support/mysql_treasury_resolution_barrier_probe.php');
        $workers = [];

        try {
            $this->assertSame('mysql_treasury_resolution_barrier_probe.php', basename($probe));
            $this->assertNotSame('mysql_treasury_resolution_worker.php', basename($probe));
            $this->assertTrue(is_dir($directory));
            $this->assertTrue(is_writable($directory));
            foreach ([$firstReady, $secondReady] as $ready) {
                $environment = array_merge(getenv() ?: [], ['APP_ENV' => 'testing']);
                $process = proc_open([PHP_BINARY, $probe, '1', '1', $ready, $release, 'barrier_probe'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, base_path(), $environment);
                $this->assertIsResource($process);
                fclose($pipes[0]);
                stream_set_blocking($pipes[1], false);
                stream_set_blocking($pipes[2], false);
                $workers[] = ['process' => $process, 'stdout' => $pipes[1], 'stderr' => $pipes[2]];
            }

            $readyDeadline = microtime(true) + 5;
            while (microtime(true) < $readyDeadline) {
                clearstatcache(true, $firstReady);
                clearstatcache(true, $secondReady);
                if (is_file($firstReady) && is_file($secondReady)) {
                    break;
                }
                usleep(25_000);
            }
            clearstatcache(true, $firstReady);
            clearstatcache(true, $secondReady);
            $this->assertTrue(is_file($firstReady));
            $this->assertTrue(is_file($secondReady));
            $this->assertSame(7, file_put_contents($release, 'release', LOCK_EX));
            clearstatcache(true, $release);
            $this->assertTrue(is_file($release));
            $this->assertSame('release', file_get_contents($release));

            foreach ($workers as $worker) {
                $deadline = microtime(true) + 5;
                $workerExitCode = null;
                while (microtime(true) < $deadline) {
                    $status = proc_get_status($worker['process']);
                    if (! $status['running']) {
                        $workerExitCode = $status['exitcode'] >= 0 ? $status['exitcode'] : null;
                        break;
                    }
                    usleep(25_000);
                }
                $stdout = stream_get_contents($worker['stdout']) ?: '';
                $stderr = stream_get_contents($worker['stderr']) ?: '';
                fclose($worker['stdout']);
                fclose($worker['stderr']);
                $exitCode = $workerExitCode ?? proc_close($worker['process']);
                if ($workerExitCode !== null) {
                    proc_close($worker['process']);
                }
                $this->assertSame(0, $exitCode, $stderr);
                $this->assertSame(['result' => 'success', 'ok' => true], json_decode($stdout, true));
            }
            $workers = [];
        } finally {
            foreach ($workers as $worker) {
                @proc_terminate($worker['process']);
                @fclose($worker['stdout']);
                @fclose($worker['stderr']);
                @proc_close($worker['process']);
            }
            @unlink($firstReady);
            @unlink($secondReady);
            @unlink($release);
            @rmdir($directory);
        }

        $this->assertDirectoryDoesNotExist($directory);
    }

    public function test_concurrent_resolution_opening_is_idempotent(): void
    {
        $this->requireIsolatedMysql();
        for ($iteration = 1; $iteration <= 10; $iteration++) {
            $fixture = $this->fixture("open-{$iteration}");
            $submission = $fixture['submission'];
            $order = $fixture['order'];
            $reservation = $fixture['reservation'];
            $inventory = $fixture['inventory'];
            $results = $this->race($submission->id, $fixture['treasury']->id, 'open_resolution', 'open_resolution');
            $summary = "iteration={$iteration} ".$this->safeRaceSummary($results);

            $this->assertCount(2, $results);
            foreach (['first', 'second'] as $worker) {
                $this->assertTrue($results[$worker]['barrier_released'] ?? false, $summary);
                $this->assertSame(0, $results[$worker]['exit_code'], $summary);
                $this->assertSame('success', $results[$worker]['result'], $summary);
                $this->assertIsArray($results[$worker]['json'], $summary);
                $this->assertSame('success', $results[$worker]['json']['result'] ?? null, $summary);
                $this->assertTrue((bool) ($results[$worker]['json']['ok'] ?? false), $summary);
            }
            if (($results['first']['json']['case_id'] ?? null) !== null && ($results['second']['json']['case_id'] ?? null) !== null) {
                $this->assertSame($results['first']['json']['case_id'], $results['second']['json']['case_id'], $summary);
            }
            if (($results['first']['json']['transaction_id'] ?? null) !== null && ($results['second']['json']['transaction_id'] ?? null) !== null) {
                $this->assertSame($results['first']['json']['transaction_id'], $results['second']['json']['transaction_id'], $summary);
            }

            $this->assertSame(1, PaymentTransaction::where('payment_submission_id', $submission->id)->where('status', PaymentTransaction::RECEIVED)->count());
            $this->assertSame(1, PaymentResolutionCase::where('payment_submission_id', $submission->id)->where('status', PaymentResolutionCase::OPEN)->count());
            $this->assertSame(1, PaymentSubmissionHistory::where('payment_submission_id', $submission->id)->where('event', 'resolution_opened')->count());
            $this->assertSame(1, PaymentResolutionHistory::whereHas('resolutionCase', fn ($query) => $query->where('payment_submission_id', $submission->id))->where('event', 'opened')->count());
            $this->assertSame(PaymentSubmission::MANUAL_RESOLUTION_REQUIRED, $submission->fresh()->status);
            $this->assertSame('pending', $order->fresh()->payment_status);
            $freshReservation = $reservation->fresh();
            $this->assertSame($fixture['initial']['reservation']['status'], $freshReservation->status);
            $this->assertSame($fixture['initial']['reservation']['expires_at']?->toISOString(), $freshReservation->expires_at?->toISOString());
            $this->assertSame($fixture['initial']['reservation']['resolution_case_id'], $freshReservation->resolution_case_id);
            $this->assertSame($fixture['initial']['inventory'], $inventory->fresh()->only(['quantity', 'reserved_quantity']));
            $this->assertSame($fixture['initial']['product_stock'], $fixture['product']->fresh()->stock);
            $this->assertSame(0, InventoryMovement::where('reference_type', 'order')->where('reference_id', (string) $order->id)->count());
        }
    }

    public function test_resolution_race_barrier_probe_uses_the_worker_protocol(): void
    {
        $this->requireIsolatedMysql();

        $results = $this->race(1, 1, 'barrier_probe', 'barrier_probe');

        foreach (['first', 'second'] as $worker) {
            $this->assertSame(0, $results[$worker]['exit_code'], $this->safeRaceSummary($results));
            $this->assertSame('success', $results[$worker]['result'], $this->safeRaceSummary($results));
        }
    }

    public function test_resolution_opening_racing_expiration_is_consistent(): void
    {
        $this->requireIsolatedMysql();
        $completeResolution = 0;
        $openingConflict = 0;
        $openingConflicts = 0;
        for ($iteration = 1; $iteration <= 10; $iteration++) {
            $fixture = $this->fixture("expiration-{$iteration}");
            $submission = $fixture['submission'];
            $order = $fixture['order'];
            $reservation = $fixture['reservation'];
            $inventory = $fixture['inventory'];
            $results = $this->race($submission->id, $fixture['treasury']->id, 'open_resolution', 'expire_reservation');
            $summary = "iteration={$iteration} ".$this->safeRaceSummary($results);

            foreach (['first', 'second'] as $worker) {
                $this->assertTrue($results[$worker]['barrier_released'] ?? false, $summary);
                $this->assertSame(0, $results[$worker]['exit_code'], $summary);
                $this->assertContains($results[$worker]['result'], ['success', 'http_response'], $summary);
                $this->assertNotSame('unexpected_error', $results[$worker]['result'], $summary);
            }

            $transactions = PaymentTransaction::query()->where('payment_submission_id', $submission->id)->where('status', PaymentTransaction::RECEIVED)->get();
            $cases = PaymentResolutionCase::query()->where('payment_submission_id', $submission->id)->get();
            $submissionHistories = PaymentSubmissionHistory::query()->where('payment_submission_id', $submission->id)->where('event', 'resolution_opened')->get();
            $resolutionHistories = PaymentResolutionHistory::query()->whereIn('payment_resolution_case_id', $cases->pluck('id'))->where('event', 'opened')->get();

            $this->assertLessThanOrEqual(1, $transactions->count());
            $this->assertLessThanOrEqual(1, $cases->count());
            $this->assertLessThanOrEqual(1, $submissionHistories->count());
            $this->assertLessThanOrEqual(1, $resolutionHistories->count());
            $this->assertSame(PaymentTransaction::query()->where('payment_submission_id', $submission->id)->count(), $transactions->count());

            if ($cases->isNotEmpty()) {
                $completeResolution++;
                $this->assertSame(1, $transactions->count(), $summary);
                $this->assertSame(1, $submissionHistories->count(), $summary);
                $this->assertSame(1, $resolutionHistories->count(), $summary);
                $this->assertSame(PaymentSubmission::MANUAL_RESOLUTION_REQUIRED, $submission->fresh()->status);
            } else {
                $openingConflict++;
                $openingConflicts++;
                $this->assertSame(0, $transactions->count(), $summary);
                $this->assertSame(0, $submissionHistories->count(), $summary);
                $this->assertSame(0, $resolutionHistories->count(), $summary);
                $this->assertSame(PaymentSubmission::PENDING_REVIEW, $submission->fresh()->status);
                $this->assertSame('http_response', $results['first']['result'], $summary);
                $this->assertSame(409, $results['first']['json']['status'] ?? null, $summary);
            }

            $freshOrder = $order->fresh();
            $freshReservation = $reservation->fresh();
            $freshInventory = $inventory->fresh();
            $this->assertSame('pending', $freshOrder->payment_status);
            $this->assertNull($freshOrder->paid_at);
            $this->assertSame(0, PaymentTransaction::query()->where('order_id', $order->id)->where('status', 'approved')->count());
            $this->assertSame(InventoryReservation::EXPIRED, $freshReservation->status);
            $this->assertSame('canceled', $freshOrder->status);
            $this->assertSame($fixture['initial']['inventory']['quantity'], $freshInventory->quantity);
            $this->assertSame(0, (int) $freshInventory->reserved_quantity);
            $this->assertSame($fixture['initial']['product_stock'], $fixture['product']->fresh()->stock);
            $this->assertGreaterThanOrEqual(0, (int) $freshInventory->quantity);
            $this->assertGreaterThanOrEqual(0, (int) $freshInventory->reserved_quantity);
            $this->assertSame(0, InventoryMovement::query()->where('reference_type', 'order')->where('reference_id', (string) $order->id)->where('type', InventoryMovement::SALE)->count());
        }
        $this->assertSame(10, $completeResolution + $openingConflict);
        $this->assertSame($openingConflict, $openingConflicts);
        fwrite(STDERR, "resolution-expiration distribution: complete={$completeResolution}, opening_conflict={$openingConflict}\n");
    }

    public function test_resolution_opening_racing_manual_cancellation_is_consistent(): void
    {
        $this->requireIsolatedMysql();
        $openingWins = 0;
        $cancellationWins = 0;
        for ($iteration = 1; $iteration <= 10; $iteration++) {
            $fixture = $this->fixture("cancellation-{$iteration}");
            $submission = $fixture['submission'];
            $order = $fixture['order'];
            $reservation = $fixture['reservation'];
            $inventory = $fixture['inventory'];
            $this->assertSame(InventoryReservation::ACTIVE, $reservation->status);
            $this->assertTrue($reservation->expires_at->isPast());
            $this->assertTrue($order->reserved_until->isPast());
            $this->assertSame('pending', $order->status);
            $this->assertSame('pending', $order->payment_status);
            $this->assertNull($order->paid_at);
            $this->assertSame(PaymentSubmission::PENDING_REVIEW, $submission->status);
            $results = $this->race($submission->id, $fixture['treasury']->id, 'open_resolution', 'cancel_order');
            $summary = "iteration={$iteration} ".$this->safeRaceSummary($results);

            $this->assertTrue($results['first']['barrier_released'] ?? false, $summary);
            $this->assertTrue($results['second']['barrier_released'] ?? false, $summary);
            $this->assertSame(0, $results['first']['exit_code'], $summary);
            $this->assertContains($results['first']['result'], ['success', 'http_response'], $summary);
            $this->assertSame(0, $results['second']['exit_code'], $summary);
            $this->assertSame('success', $results['second']['result'], $summary);

            $cases = PaymentResolutionCase::query()->where('payment_submission_id', $submission->id)->get();
            $transactions = PaymentTransaction::query()->where('payment_submission_id', $submission->id)->where('status', PaymentTransaction::RECEIVED)->get();
            $submissionHistories = PaymentSubmissionHistory::query()->where('payment_submission_id', $submission->id)->where('event', 'resolution_opened')->get();
            $resolutionHistories = PaymentResolutionHistory::query()->whereIn('payment_resolution_case_id', $cases->pluck('id'))->where('event', 'opened')->get();
            $freshOrder = $order->fresh();
            $freshReservation = $reservation->fresh();
            $freshInventory = $inventory->fresh();

            if ($cases->isNotEmpty()) {
                $openingWins++;
                $this->assertSame(1, $transactions->count(), $summary);
                $this->assertSame(1, $submissionHistories->count(), $summary);
                $this->assertSame(1, $resolutionHistories->count(), $summary);
                $this->assertSame(PaymentSubmission::MANUAL_RESOLUTION_REQUIRED, $submission->fresh()->status);
                $this->assertSame('success', $results['first']['result'], $summary);
            } else {
                $cancellationWins++;
                $this->assertSame(0, $transactions->count(), $summary);
                $this->assertSame(0, $submissionHistories->count(), $summary);
                $this->assertSame(0, $resolutionHistories->count(), $summary);
                $this->assertSame(PaymentSubmission::PENDING_REVIEW, $submission->fresh()->status);
                $this->assertSame('http_response', $results['first']['result'], $summary);
                $this->assertSame(409, $results['first']['json']['status'] ?? null, $summary);
                $this->assertSame('payment_resolution_conflict', $results['first']['json']['code'] ?? null, $summary);
            }

            $this->assertSame('canceled', $freshOrder->status);
            $this->assertSame('pending', $freshOrder->payment_status);
            $this->assertNull($freshOrder->paid_at);
            $this->assertSame(InventoryReservation::RELEASED, $freshReservation->status);
            $this->assertSame($fixture['initial']['inventory']['quantity'], $freshInventory->quantity);
            $this->assertSame(0, (int) $freshInventory->reserved_quantity);
            $this->assertSame($fixture['initial']['product_stock'], $fixture['product']->fresh()->stock);
            $this->assertGreaterThanOrEqual(0, (int) $freshInventory->quantity);
            $this->assertGreaterThanOrEqual(0, (int) $freshInventory->reserved_quantity);
            $this->assertSame(0, PaymentTransaction::query()->where('order_id', $order->id)->where('status', 'approved')->count());
            $this->assertSame(0, InventoryMovement::query()->where('reference_type', 'order')->where('reference_id', (string) $order->id)->where('type', InventoryMovement::SALE)->count());
        }
        $this->assertSame(10, $openingWins + $cancellationWins);
        fwrite(STDERR, "resolution-cancellation distribution: opening_wins={$openingWins}, cancellation_wins={$cancellationWins}\n");
    }

    private function requireIsolatedMysql(): void
    {
        $database = DB::selectOne('SELECT DATABASE() AS name')->name ?? null;
        $version = DB::selectOne('SELECT VERSION() AS version')->version ?? '';
        if (app()->environment() !== 'testing' || config('app.debug') || DB::connection()->getDriverName() !== 'mysql' || $database !== 'lubristore_2b4_resolution_certification_test' || ! str_starts_with($version, '8.')) {
            $this->fail('The isolated MySQL certification guard failed.');
        }
    }

    /** @return array{submission:PaymentSubmission,order:Order,reservation:InventoryReservation,inventory:WarehouseInventory,product:Product,treasury:User,initial:array<string,mixed>} */
    private function fixture(string $suffix, bool $expiredReservation = true): array
    {
        $suffix .= '-'.substr(hash('sha256', uniqid('', true)), 0, 8);
        $reservationDeadline = $expiredReservation ? now()->subMinute() : now()->addMinutes(10);
        $treasury = User::factory()->create(['role' => 'treasury', 'is_active' => true]);
        $customer = User::factory()->create(['role' => 'customer', 'is_active' => true]);
        $branch = Branch::create(['code' => "BR-{$suffix}", 'name' => 'Resolution branch', 'address' => 'Test', 'is_active' => true]);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'code' => "WH-{$suffix}", 'name' => 'Resolution warehouse', 'is_active' => true]);
        $product = Product::create(['name' => "Resolution {$suffix}", 'slug' => "resolution-{$suffix}", 'sku' => "RES-{$suffix}", 'price' => '20.00', 'sale_price' => '20.00', 'is_active' => true, 'stock' => 2]);
        Product::query()->whereKey($product->id)->update(['stock' => 2]);
        $product = $product->fresh();
        $inventory = WarehouseInventory::create(['warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'quantity' => 2, 'reserved_quantity' => 1]);
        $order = Order::create(['user_id' => $customer->id, 'status' => 'pending', 'total' => '20.00', 'shipping_info' => ['recipient_name' => 'Test'], 'payment_method' => 'transferencia', 'payment_status' => 'pending', 'reserved_until' => $reservationDeadline, 'delivery_type' => 'delivery', 'tracking_status' => 'pending', 'fulfillment_status' => 'reserved']);
        $item = $order->items()->create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'quantity' => 1, 'price' => '20.00', 'subtotal' => '20.00']);
        $reservation = InventoryReservation::create(['order_id' => $order->id, 'order_item_id' => $item->id, 'product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'reservation_sequence' => 1, 'quantity' => 1, 'status' => InventoryReservation::ACTIVE, 'expires_at' => $reservationDeadline, 'idempotency_key' => "resolution-reservation-{$suffix}"]);
        $submission = PaymentSubmission::create(['order_id' => $order->id, 'channel' => 'yape', 'status' => PaymentSubmission::PENDING_REVIEW, 'expected_amount' => '20.00', 'currency' => 'PEN', 'operation_number' => "RES{$suffix}", 'normalized_operation_number' => "RES{$suffix}", 'duplicate_fingerprint' => hash('sha256', "resolution-{$suffix}"), 'declared_paid_at' => now(), 'origin_phone_last4' => '1234', 'receiving_account_snapshot' => ['channel' => 'yape'], 'submitted_by' => $customer->id, 'submitted_at' => now(), 'idempotency_key' => "resolution-submission-{$suffix}"]);

        $this->assertSame(0, PaymentTransaction::where('payment_submission_id', $submission->id)->count());
        $this->assertSame(0, PaymentResolutionCase::where('payment_submission_id', $submission->id)->count());
        $this->assertSame(0, PaymentSubmissionHistory::where('payment_submission_id', $submission->id)->where('event', 'resolution_opened')->count());
        $this->assertSame(0, PaymentResolutionHistory::whereHas('resolutionCase', fn ($query) => $query->where('payment_submission_id', $submission->id))->count());

        return [
            'submission' => $submission, 'order' => $order, 'reservation' => $reservation,
            'inventory' => $inventory, 'product' => $product, 'treasury' => $treasury,
            'initial' => [
                'order' => $order->only(['status', 'payment_status', 'paid_at']),
                'reservation' => $reservation->only(['status', 'expires_at', 'resolution_case_id']),
                'inventory' => $inventory->only(['quantity', 'reserved_quantity']),
                'product_stock' => $product->stock,
                'inventory_movements' => InventoryMovement::where('reference_type', 'order')->where('reference_id', (string) $order->id)->count(),
            ],
        ];
    }

    private function race(int $submissionId, int $actorId, string $first, string $second): array
    {
        $this->requireIsolatedMysql();
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'lubristore-resolution-race-'.uniqid('', true);
        if (! mkdir($directory) && ! is_dir($directory)) {
            $this->fail('Unable to create the isolated resolution race directory.');
        }
        $this->assertTrue(is_dir($directory));
        $this->assertTrue(is_writable($directory));
        $firstReady = $directory.DIRECTORY_SEPARATOR.'ready-first';
        $secondReady = $directory.DIRECTORY_SEPARATOR.'ready-second';
        $release = $directory.DIRECTORY_SEPARATOR.'release';
        if (@file_put_contents($release, '') === false) {
            $this->fail('Unable to prepare the isolated resolution release file.');
        }
        $worker = base_path('tests/Support/mysql_treasury_resolution_worker.php');
        $workers = [];
        $environment = array_merge(getenv() ?: [], ['APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'lubristore_2b4_resolution_certification_test']);

        try {
            foreach (['first' => [$first, $firstReady], 'second' => [$second, $secondReady]] as $name => [$action, $readyPath]) {
                if (! in_array($action, ['open_resolution', 'expire_reservation', 'cancel_order', 'barrier_probe'], true)) {
                    $this->fail('Resolution worker action is not allowlisted.');
                }
                $process = proc_open([PHP_BINARY, $worker, (string) $submissionId, (string) $actorId, $readyPath, $release, $action], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, base_path(), $environment);
                if (! is_resource($process)) {
                    $this->fail('Unable to start a resolution worker.');
                }
                fclose($pipes[0]);
                stream_set_blocking($pipes[1], false);
                stream_set_blocking($pipes[2], false);
                $workers[$name] = [
                    'process' => $process,
                    'stdout_pipe' => $pipes[1],
                    'stderr_pipe' => $pipes[2],
                    'stdout' => '',
                    'stderr' => '',
                    'exit_code' => null,
                    'timed_out' => false,
                    'timeout_result' => null,
                    'exited_before_ready' => false,
                    'ready_path' => $readyPath,
                    'closed' => false,
                    'running' => true,
                ];
            }

            $readyDeadline = microtime(true) + 10;
            while (microtime(true) < $readyDeadline) {
                clearstatcache(true, $firstReady);
                clearstatcache(true, $secondReady);
                if (is_file($firstReady) && is_file($secondReady)) {
                    break;
                }
                $this->drainWorkers($workers, false);
                if ($this->workerExitedBeforeReady($workers)) {
                    return $this->finalizeWorkers($workers);
                }
                usleep(25_000);
            }
            clearstatcache(true, $firstReady);
            clearstatcache(true, $secondReady);
            $this->drainWorkers($workers, false);
            if ($this->workerExitedBeforeReady($workers)) {
                return $this->finalizeWorkers($workers);
            }
            if (! is_file($firstReady) || ! is_file($secondReady)) {
                $this->captureReadyTimeoutDiagnostic($workers, $firstReady, $secondReady);
                foreach ($workers as &$workerState) {
                    $workerState['timed_out'] = true;
                    $workerState['timeout_result'] = 'ready_timeout';
                }
                unset($workerState);
                $this->terminateTimedOutWorkers($workers);

                return $this->finalizeWorkers($workers);
            }

            if (@file_put_contents($release, 'release') === false) {
                $this->fail('Unable to release the isolated resolution race barrier.');
            }
            foreach ($workers as &$workerState) {
                $workerState['barrier_released'] = true;
            }
            unset($workerState);

            $executionDeadline = microtime(true) + 30;
            while (microtime(true) < $executionDeadline && $this->workersAreRunning($workers)) {
                $this->drainWorkers($workers);
                usleep(25_000);
            }
            foreach ($workers as &$workerState) {
                if ($this->workerIsRunning($workerState)) {
                    $workerState['timed_out'] = true;
                    $workerState['timeout_result'] = 'execution_timeout';
                }
            }
            unset($workerState);
            $this->terminateTimedOutWorkers($workers);

            return $this->finalizeWorkers($workers);
        } finally {
            $this->cleanupWorkers($workers, $directory, $firstReady, $secondReady, $release);
        }
    }

    private function drainWorkers(array &$workers, bool $readRunningWorkers = true): void
    {
        foreach ($workers as &$worker) {
            if (! $worker['closed']) {
                $status = proc_get_status($worker['process']);
                $worker['running'] = $status['running'];
                if (! $status['running'] && $status['exitcode'] >= 0) {
                    $worker['exit_code'] = $status['exitcode'];
                }
                if ($readRunningWorkers || ! $worker['running']) {
                    $worker['stdout'] .= stream_get_contents($worker['stdout_pipe']) ?: '';
                    $worker['stderr'] .= stream_get_contents($worker['stderr_pipe']) ?: '';
                }
            }
        }
        unset($worker);
    }

    private function workersAreRunning(array $workers): bool
    {
        foreach ($workers as $worker) {
            if ($this->workerIsRunning($worker)) {
                return true;
            }
        }

        return false;
    }

    private function workerIsRunning(array $worker): bool
    {
        return ! $worker['closed'] && $worker['running'];
    }

    private function workerExitedBeforeReady(array &$workers): bool
    {
        $exited = false;
        foreach ($workers as &$worker) {
            if (! $worker['running'] && ! is_file($worker['ready_path'])) {
                $worker['exited_before_ready'] = true;
                $exited = true;
            }
        }
        unset($worker);

        return $exited;
    }

    private function captureReadyTimeoutDiagnostic(array &$workers, string $firstReady, string $secondReady): void
    {
        $diagnostic = [
            'first_ready_exists' => is_file($firstReady),
            'second_ready_exists' => is_file($secondReady),
            'first_process_running' => $this->workerIsRunning($workers['first']),
            'second_process_running' => $this->workerIsRunning($workers['second']),
            'first_exit_code' => $workers['first']['exit_code'],
            'second_exit_code' => $workers['second']['exit_code'],
        ];

        foreach ($workers as &$worker) {
            $worker['ready_timeout_diagnostic'] = $diagnostic;
        }
        unset($worker);
    }

    private function terminateTimedOutWorkers(array &$workers): void
    {
        foreach ($workers as &$worker) {
            if ($worker['timed_out'] && $this->workerIsRunning($worker)) {
                @proc_terminate($worker['process']);
            }
        }
        unset($worker);

        $deadline = microtime(true) + 1;
        while (microtime(true) < $deadline && $this->workersAreRunning($workers)) {
            $this->drainWorkers($workers);
            usleep(25_000);
        }
        foreach ($workers as &$worker) {
            if ($worker['timed_out'] && $this->workerIsRunning($worker)) {
                @proc_terminate($worker['process'], 9);
            }
        }
        unset($worker);
    }

    private function finalizeWorkers(array &$workers): array
    {
        $results = [];
        foreach ($workers as $name => &$worker) {
            $this->drainWorkers($workers);
            $worker['stdout'] .= stream_get_contents($worker['stdout_pipe']) ?: '';
            $worker['stderr'] .= stream_get_contents($worker['stderr_pipe']) ?: '';
            fclose($worker['stdout_pipe']);
            fclose($worker['stderr_pipe']);
            $worker['closed'] = true;
            $closeCode = proc_close($worker['process']);
            $exitCode = $worker['exit_code'] ?? $closeCode;
            $decoded = json_decode($worker['stdout'], true);
            $result = $worker['exited_before_ready']
                ? 'worker_exited_before_ready'
                : ($worker['timeout_result'] ?? (! is_array($decoded) ? 'invalid_json' : ($decoded['result'] ?? 'worker_error')));
            $results[$name] = [
                'result' => $result,
                'exit_code' => $exitCode,
                'stdout' => $worker['stdout'],
                'stderr' => $worker['stderr'],
                'json' => is_array($decoded) ? $decoded : null,
                'ready_diagnostic' => $worker['ready_timeout_diagnostic'] ?? null,
                'barrier_released' => $worker['barrier_released'] ?? false,
            ];
        }
        unset($worker);

        return $results;
    }

    private function cleanupWorkers(array &$workers, string $directory, string $firstReady, string $secondReady, string $release): void
    {
        foreach ($workers as &$worker) {
            if (! $worker['closed']) {
                if ($this->workerIsRunning($worker)) {
                    @proc_terminate($worker['process']);
                }
                $this->drainWorkers($workers);
                if (is_resource($worker['stdout_pipe'])) {
                    fclose($worker['stdout_pipe']);
                }
                if (is_resource($worker['stderr_pipe'])) {
                    fclose($worker['stderr_pipe']);
                }
                @proc_close($worker['process']);
                $worker['closed'] = true;
            }
        }
        unset($worker);

        @unlink($firstReady);
        @unlink($secondReady);
        @unlink($release);
        @rmdir($directory);
    }

    /** @param array{first:array<string,mixed>,second:array<string,mixed>} $results */
    private function safeRaceSummary(array $results): string
    {
        $summary = [];
        foreach (['first', 'second'] as $worker) {
            $json = is_array($results[$worker]['json'] ?? null) ? $results[$worker]['json'] : [];
            $summary[$worker] = [
                'exit_code' => $results[$worker]['exit_code'] ?? null,
                'result' => $results[$worker]['result'] ?? null,
                'ok' => $json['ok'] ?? null,
                'status' => $json['status'] ?? null,
                'code' => $json['code'] ?? null,
                'message' => isset($json['message']) ? $this->safeWorkerOutput((string) $json['message']) : null,
                'ready_diagnostic' => $results[$worker]['ready_diagnostic'] ?? null,
            ];
        }

        return json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: 'race summary unavailable';
    }

    private function safeWorkerOutput(string $value): string
    {
        $value = preg_replace('/(?:APP_KEY|DB_PASSWORD|mysql:\/\/)[^\s]*/i', '[redacted]', $value) ?? '';
        $value = preg_replace('/(?:select|insert|update|delete)\s+.+/i', '[sql redacted]', $value) ?? '';

        return substr(str_replace(["\r", "\n"], ' ', $value), 0, 500);
    }
}
