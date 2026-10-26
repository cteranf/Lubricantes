<?php

namespace Tests\Feature;

use App\Models\PaymentTransaction;
use App\Services\PaymentGateway\MercadoPagoGateway;
use App\Services\PaymentProcessingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\SyntheticMercadoPagoLink;
use Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RemotePaymentStatusTransitionTest extends TestCase
{
    use RefreshDatabase;
    use SyntheticMercadoPagoLink;

    public function test_same_remote_payment_pending_then_approved_has_one_approved_financial_entry(): void
    {
        $this->withRemoteLink(function ($transport, $order, $preference) {
            $gateway = new MercadoPagoGateway;
            $processor = app(PaymentProcessingService::class);
            $transport->payment['status'] = 'pending';
            $processor->process($preference, $gateway->verifyPayment((string) $transport->paymentId));
            $entry = PaymentTransaction::where('order_id', $order->id)->sole();
            $entryId = $entry->id;
            $this->assertSame('pending', $order->refresh()->payment_status);
            $this->assertSame('pending', $entry->provider_status);

            $transport->payment['status'] = 'approved';
            $processor->process($preference, $gateway->verifyPayment((string) $transport->paymentId));
            $this->assertSame('approved', $order->refresh()->payment_status);
            $this->assertDatabaseCount('payment_transactions', 1);
            $this->assertSame($entryId, PaymentTransaction::sole()->id);
            $this->assertSame(PaymentTransaction::APPROVED, $entry->refresh()->status, 'An approved order must not retain a failed financial entry for the same remotely verified payment.');
            $this->assertSame('approved', $entry->provider_status);

            $before = $entry->getRawOriginal();
            $processor->process($preference, $gateway->verifyPayment((string) $transport->paymentId));
            $this->assertDatabaseCount('payment_transactions', 1);
            $this->assertTrue($before === $entry->refresh()->getRawOriginal(), 'Repeated approval must preserve the financial entry.');
        });
    }

    /** @dataProvider pendingStates */
    public function test_pending_approval_consumes_reserved_inventory_once_and_terminal_entry_is_immutable(string $initial): void
    {
        $this->withRemoteLink(function ($transport, $order, $preference) use ($initial) {
            $inventory = app(\App\Services\InventoryService::class);
            $warehouse = $inventory->defaultWarehouse();
            $product = \App\Models\Product::create(['name' => 'Synthetic item', 'slug' => 'synthetic-'.bin2hex(random_bytes(16)), 'sku' => 'SYN-'.bin2hex(random_bytes(8)), 'price' => '10.25', 'is_active' => true]);
            $inventory->initializeProduct($product, 5, $warehouse);
            $item = $order->items()->create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'quantity' => 1, 'price' => '10.25', 'subtotal' => '10.25']);
            $inventory->reserveForOrder($item, now()->addMinutes(30));
            $gateway = new MercadoPagoGateway;
            $processor = app(PaymentProcessingService::class);
            $transport->payment['status'] = $initial;
            $processor->process($preference, $gateway->verifyPayment((string) $transport->paymentId));
            $entry = PaymentTransaction::sole();
            $this->assertSame(PaymentTransaction::PENDING, $entry->status);
            $this->assertNull($entry->failed_at);
            $this->assertSame('active', $item->currentReservation->status);
            $transport->payment['status'] = 'approved';
            $processor->process($preference, $gateway->verifyPayment((string) $transport->paymentId));
            $this->assertSame(PaymentTransaction::APPROVED, $entry->refresh()->status);
            $this->assertSame('consumed', $item->currentReservation()->first()->status);
            $this->assertSame(4, \App\Models\WarehouseInventory::where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->sole()->quantity);
            $before = hash('sha256', json_encode([DB::table('payment_transactions')->get(), DB::table('inventory_movements')->get(), DB::table('inventory_reservations')->get(), DB::table('order_fulfillment_histories')->get()]));
            $processor->process($preference, $gateway->verifyPayment((string) $transport->paymentId));
            $this->assertTrue(hash_equals($before, hash('sha256', json_encode([DB::table('payment_transactions')->get(), DB::table('inventory_movements')->get(), DB::table('inventory_reservations')->get(), DB::table('order_fulfillment_histories')->get()]))), 'Repeated approval must not duplicate financial, stock or history writes.');
            $blocked = false;
            try {
                $entry->update(['amount' => '12.00']);
            } catch (\LogicException) {
                $blocked = true;
            }
            $this->assertTrue($blocked, 'Approved financial entries remain immutable.');
        });
    }

    public static function pendingStates(): array
    {
        return [['pending'], ['in_process']];
    }

    public function test_inventory_failure_rolls_back_pending_approval_including_financial_entry(): void
    {
        $this->withRemoteLink(function ($transport, $order, $preference) {
            $gateway = new MercadoPagoGateway;
            $processor = app(PaymentProcessingService::class);
            $transport->payment['status'] = 'pending';
            $processor->process($preference, $gateway->verifyPayment((string) $transport->paymentId));
            $before = PaymentTransaction::sole()->getRawOriginal();
            $transport->payment['status'] = 'approved';
            $this->app->instance(\App\Services\InventoryService::class, \Mockery::mock(\App\Services\InventoryService::class)->shouldReceive('consumeOrderReservation')->once()->andThrow(new \App\Exceptions\InventoryException('Synthetic stock conflict'))->getMock());
            // Re-resolve so the actual processor uses the failing stock dependency.
            $rejected = false;
            try {
                app(PaymentProcessingService::class)->process($preference, $gateway->verifyPayment((string) $transport->paymentId));
            } catch (\App\Exceptions\InventoryException) {
                $rejected = true;
            }
            $this->assertTrue($rejected, 'Stock errors must propagate.');
            $this->assertTrue($before === PaymentTransaction::sole()->getRawOriginal(), 'Stock failure must roll back the financial transition.');
            $this->assertSame('pending', $order->refresh()->payment_status);
        });
    }

    /** @dataProvider unavailableOrders */
    public function test_late_or_crossed_payment_is_rejected_before_financial_writes(string $case): void
    {
        $this->withRemoteLink(function ($transport, $order, $preference) use ($case) {
            if ($case === 'expired') {
                $order->update(['reserved_until' => now()->subMinute()]);
            } elseif ($case === 'canceled') {
                $order->update(['status' => 'canceled']);
            } else {
                $other = $order->replicate(['current_payment_preference_id']);
                $other->save();
                $order->update(['current_payment_preference_id' => null]);
                $preference->update(['order_id' => $other->id]);
                $other->update(['current_payment_preference_id' => $preference->id]);
                // A stale locator still points to the original order.
                $preference->order_id = $order->id;
            }
            $gateway = new MercadoPagoGateway;
            $evidence = $gateway->verifyPayment((string) $transport->paymentId);
            $writes = new \ArrayObject;
            DB::listen(static function ($query) use ($writes) {
                if (preg_match('/^\s*(insert|update|delete|replace)\b/i', $query->sql)) {
                    $writes->append(true);
                }
            });
            $rejected = false;
            try {
                app(PaymentProcessingService::class)->process($preference, $evidence);
            } catch (ValidationException) {
                $rejected = true;
            }
            $this->assertTrue($rejected, 'Unavailable or crossed order must be rejected.');
            $this->assertCount(0, $writes);
            $this->assertDatabaseCount('payment_transactions', 0);
        });
    }

    public static function unavailableOrders(): array
    {
        return [['expired'], ['canceled'], ['crossed']];
    }

    /** @dataProvider legacyRecordedStatuses */
    public function test_legacy_failed_entry_can_finalize_only_if_remote_evidence_was_pending(string $providerStatus, bool $canApprove): void
    {
        $this->withRemoteLink(function ($transport, $order, $preference) use ($providerStatus, $canApprove) {
            // Reproduce the former application writer through its real service,
            // entirely in SQLite; no manual financial repair or row updates.
            $entry = DB::transaction(fn () => app(\App\Services\OrderPaymentService::class)->recordCardAttempt(
                $order, (string) $transport->paymentId, PaymentTransaction::FAILED,
                ['gateway' => 'mercadopago', 'payment_preference_id' => $preference->id,
                    'provider_payment_id' => (string) $transport->paymentId,
                    'provider_status' => $providerStatus, 'verified_at' => now()], null, 'PEN'
            ), 3);
            $before = $entry->refresh()->getRawOriginal();
            $evidence = (new MercadoPagoGateway)->verifyPayment((string) $transport->paymentId);
            $rejected = false;
            try {
                app(PaymentProcessingService::class)->process($preference, $evidence);
            } catch (ValidationException) {
                $rejected = true;
            }
            $this->assertSame(! $canApprove, $rejected);
            $this->assertDatabaseCount('payment_transactions', 1);
            if ($canApprove) {
                $this->assertSame(PaymentTransaction::APPROVED, $entry->refresh()->status);
                $this->assertSame('approved', $entry->provider_status);
                $this->assertNull($entry->failed_at);
                $this->assertNotNull($entry->confirmed_at);
                $this->assertSame('approved', $order->refresh()->payment_status);
            } else {
                $this->assertTrue($before === $entry->refresh()->getRawOriginal(), 'A terminal rejected entry must remain immutable.');
                $this->assertSame('pending', $order->refresh()->payment_status);
            }
        });
    }

    public static function legacyRecordedStatuses(): array
    {
        return [['pending', true], ['rejected', false]];
    }
}
