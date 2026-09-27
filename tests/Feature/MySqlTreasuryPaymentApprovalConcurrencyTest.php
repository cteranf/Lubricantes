<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\InventoryMovement;
use App\Models\InventoryReservation;
use App\Models\Order;
use App\Models\PaymentSubmission;
use App\Models\PaymentSubmissionHistory;
use App\Models\PaymentTransaction;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseInventory;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MySqlTreasuryPaymentApprovalConcurrencyTest extends TestCase
{
    public function test_ten_real_process_races_produce_one_coherent_treasury_approval(): void
    {
        $this->requireIsolatedMysql();

        for ($iteration = 1; $iteration <= 10; $iteration++) {
            [$submission, $order] = $this->fixture("race-{$iteration}");
            $treasury = User::factory()->create(['role' => 'treasury', 'is_active' => true]);
            $results = $this->race($submission->id, $treasury->id, 'approve', 'approve');

            $this->assertCount(2, $results);
            $this->assertNotContains('unexpected_error', array_column($results, 'result'));
            $this->assertSame(1, PaymentTransaction::query()->where('payment_submission_id', $submission->id)->count());
            $this->assertSame(1, PaymentSubmissionHistory::query()->where('payment_submission_id', $submission->id)->where('event', 'approved')->count());
            $this->assertSame(PaymentSubmission::APPROVED, $submission->fresh()->status);
            $this->assertSame('approved', $order->fresh()->payment_status);
            $this->assertNotNull($order->fresh()->paid_at);
            $this->assertSame(1, InventoryReservation::query()->where('order_id', $order->id)->where('status', InventoryReservation::CONSUMED)->count());
            $this->assertSame(1, InventoryMovement::query()->where('reference_type', 'order')->where('reference_id', (string) $order->id)->where('type', InventoryMovement::SALE)->count());
            $inventory = WarehouseInventory::query()->where('product_id', $order->items()->firstOrFail()->product_id)->firstOrFail();
            $this->assertSame(1, (int) $inventory->quantity);
            $this->assertSame(0, (int) $inventory->reserved_quantity);
        }
    }

    public function test_ten_real_process_races_between_expiration_and_approval_leave_no_partial_sale(): void
    {
        $this->requireIsolatedMysql();

        for ($iteration = 1; $iteration <= 10; $iteration++) {
            [$submission, $order] = $this->fixture("expiry-race-{$iteration}");
            $order->update(['reserved_until' => now()->subSecond()]);
            InventoryReservation::query()->where('order_id', $order->id)->update(['expires_at' => now()->subSecond()]);
            $treasury = User::factory()->create(['role' => 'treasury', 'is_active' => true]);
            $results = $this->race($submission->id, $treasury->id, 'approve', 'expire');

            $this->assertNotContains('unexpected_error', array_column($results, 'result'));
            $this->assertSame(0, PaymentTransaction::query()->where('payment_submission_id', $submission->id)->count());
            $this->assertSame(0, PaymentSubmissionHistory::query()->where('payment_submission_id', $submission->id)->where('event', 'approved')->count());
            $this->assertSame(0, InventoryMovement::query()->where('reference_type', 'order')->where('reference_id', (string) $order->id)->where('type', InventoryMovement::SALE)->count());
            $this->assertSame(InventoryReservation::EXPIRED, InventoryReservation::query()->where('order_id', $order->id)->value('status'));
            $this->assertSame('pending', $order->fresh()->payment_status);
            $this->assertContains('manual_resolution_required', array_filter(array_column($results, 'code')), json_encode($results));
        }
    }

    public function test_ten_real_process_races_between_cancellation_and_approval_leave_one_coherent_outcome(): void
    {
        $this->requireIsolatedMysql();

        for ($iteration = 1; $iteration <= 10; $iteration++) {
            [$submission, $order] = $this->fixture("cancel-race-{$iteration}");
            $actor = User::factory()->create(['role' => 'treasury', 'is_active' => true]);
            $results = $this->race($submission->id, $actor->id, 'approve', 'cancel');

            $this->assertNotContains('unexpected_error', array_column($results, 'result'));
            $order = $order->fresh();
            $reservations = InventoryReservation::query()->where('order_id', $order->id)->get();
            $transactions = PaymentTransaction::query()->where('payment_submission_id', $submission->id)->count();
            $sales = InventoryMovement::query()->where('reference_type', 'order')->where('reference_id', (string) $order->id)->where('type', InventoryMovement::SALE)->count();

            if ($submission->fresh()->status === PaymentSubmission::APPROVED) {
                $this->assertSame('approved', $order->payment_status);
                $this->assertSame(1, $transactions);
                $this->assertTrue($reservations->every(fn ($reservation) => $reservation->status === InventoryReservation::CONSUMED));
                $this->assertSame($reservations->count(), $sales);
            } else {
                $this->assertSame(0, $transactions);
                $this->assertSame(0, $sales);
                $this->assertSame('pending', $order->payment_status);
                $this->assertTrue($reservations->every(fn ($reservation) => in_array($reservation->status, [InventoryReservation::RELEASED, InventoryReservation::EXPIRED], true)));
            }
        }
    }

    private function requireIsolatedMysql(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql' || ! str_ends_with(DB::connection()->getDatabaseName(), '_test')) {
            $this->markTestSkipped('Requires an isolated MySQL database ending in _test.');
        }
    }

    private function fixture(string $suffix): array
    {
        $suffix .= '-'.substr(hash('sha256', uniqid('', true)), 0, 8);
        $owner = User::factory()->create();
        $branch = Branch::create(['code' => "B-{$suffix}", 'name' => 'Race branch', 'address' => 'Test', 'is_active' => true]);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'code' => "W-{$suffix}", 'name' => 'Race warehouse', 'is_active' => true, 'is_default' => false]);
        $product = Product::create(['name' => "Race {$suffix}", 'slug' => "race-{$suffix}", 'sku' => "RACE-{$suffix}", 'price' => '20.00', 'sale_price' => '20.00', 'is_active' => true]);
        Product::query()->whereKey($product->id)->update(['stock' => 2]);
        WarehouseInventory::create(['warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'quantity' => 2, 'reserved_quantity' => 1]);
        $order = Order::create(['user_id' => $owner->id, 'status' => 'pending', 'total' => '20.00', 'shipping_info' => ['recipient_name' => 'Test'], 'payment_method' => 'transferencia', 'payment_status' => 'pending', 'reserved_until' => now()->addMinutes(10), 'delivery_type' => 'delivery', 'tracking_status' => 'pending', 'fulfillment_status' => 'reserved']);
        $item = $order->items()->create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'quantity' => 1, 'price' => '20.00', 'subtotal' => '20.00']);
        InventoryReservation::create(['order_id' => $order->id, 'order_item_id' => $item->id, 'product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'quantity' => 1, 'status' => InventoryReservation::ACTIVE, 'expires_at' => now()->addMinutes(10), 'idempotency_key' => "race-reservation-{$suffix}"]);
        $submission = PaymentSubmission::create(['order_id' => $order->id, 'channel' => 'yape', 'status' => PaymentSubmission::PENDING_REVIEW, 'expected_amount' => '20.00', 'currency' => 'PEN', 'operation_number' => "RACE{$suffix}", 'normalized_operation_number' => "RACE{$suffix}", 'duplicate_fingerprint' => hash('sha256', "race-{$suffix}"), 'declared_paid_at' => now(), 'origin_phone_last4' => '1234', 'receiving_account_snapshot' => ['id' => 1], 'submitted_by' => $owner->id, 'submitted_at' => now(), 'idempotency_key' => "race-submission-{$suffix}"]);

        return [$submission, $order];
    }

    private function race(int $submissionId, int $treasuryId, string $firstAction, string $secondAction): array
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'lubristore-race-'.uniqid('', true);
        mkdir($directory);
        $barrier = $directory.DIRECTORY_SEPARATOR.'start';
        $worker = base_path('tests/Support/mysql_treasury_approval_worker.php');
        $processes = [];
        $outputs = [];
        foreach ([$firstAction, $secondAction] as $action) {
            $processes[] = proc_open([PHP_BINARY, $worker, (string) $submissionId, (string) $treasuryId, $barrier, $action], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, base_path());
            $pipes[0] && fclose($pipes[0]);
            $outputs[] = $pipes;
        }
        usleep(200_000);
        touch($barrier);
        $results = [];
        foreach ($processes as $index => $process) {
            $stdout = stream_get_contents($outputs[$index][1]);
            $stderr = stream_get_contents($outputs[$index][2]);
            fclose($outputs[$index][1]);
            fclose($outputs[$index][2]);
            $exit = proc_close($process);
            $this->assertSame('', trim($stderr));
            $this->assertSame(0, $exit);
            $results[] = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
        }
        @unlink($barrier);
        @rmdir($directory);

        return $results;
    }
}
