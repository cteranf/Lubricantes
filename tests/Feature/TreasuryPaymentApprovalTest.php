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
use App\Services\InventoryService;
use App\Services\OrderPaymentService;
use App\Services\TreasuryPaymentApprovalService;
use App\Services\TreasuryPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class TreasuryPaymentApprovalTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @dataProvider transferChannels
     */
    public function test_treasury_approves_a_pending_transfer_atomically_and_consumes_inventory(string $channel): void
    {
        [$submission, $order, $reservation, $inventory, $product] = $this->fixture($channel);
        $treasury = User::factory()->create(['role' => 'treasury']);
        Sanctum::actingAs($treasury);

        $response = $this->patchJson($this->url($submission))->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Pragma', 'no-cache')
            ->assertJsonPath('data.submission.status', PaymentSubmission::APPROVED)
            ->assertJsonPath('data.order.payment_status', 'approved');

        $transaction = PaymentTransaction::firstOrFail();
        $this->assertSame($submission->id, $transaction->payment_submission_id);
        $this->assertSame('treasury-approval-submission-'.$submission->id, $transaction->idempotency_key);
        $this->assertSame(PaymentTransaction::approvedScopeKeyForOrder($order->id), $transaction->approved_scope_key);
        $this->assertSame($treasury->id, $transaction->confirmed_by);
        $this->assertSame(PaymentTransaction::APPROVED, $transaction->status);
        $this->assertSame(1, PaymentSubmissionHistory::where('event', 'approved')->count());
        $this->assertNotNull($order->fresh()->paid_at);
        $this->assertSame(InventoryReservation::CONSUMED, $reservation->fresh()->status);
        $this->assertSame(1, (int) $inventory->fresh()->quantity);
        $this->assertSame(0, (int) $inventory->fresh()->reserved_quantity);
        $this->assertSame(1, (int) $product->fresh()->stock);
        $this->assertSame(1, InventoryMovement::count());
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertSame('reserved', $order->fresh()->fulfillment_status);
        $this->assertStringNotContainsString($transaction->idempotency_key, $response->getContent());
    }

    public function test_treasury_approval_consumes_two_reserved_products_across_two_warehouses(): void
    {
        [$submission, $order] = $this->fixture();
        $this->addSecondReservedItem($order);
        Sanctum::actingAs(User::factory()->create(['role' => 'treasury']));

        $this->patchJson($this->url($submission))->assertOk();

        $reservations = InventoryReservation::query()->where('order_id', $order->id)->orderBy('id')->get();
        $this->assertCount(2, $reservations);
        $this->assertTrue($reservations->every(fn ($reservation) => $reservation->status === InventoryReservation::CONSUMED && $reservation->consumed_at !== null));
        foreach ($reservations as $reservation) {
            $inventory = WarehouseInventory::query()->where('warehouse_id', $reservation->warehouse_id)->where('product_id', $reservation->product_id)->firstOrFail();
            $this->assertSame(0, (int) $inventory->reserved_quantity);
            $this->assertSame(1, InventoryMovement::query()->where('idempotency_key', 'sale-order-item-'.$reservation->order_item_id)->where('type', InventoryMovement::SALE)->where('quantity', $reservation->quantity)->count());
            $this->assertSame((int) WarehouseInventory::query()->where('product_id', $reservation->product_id)->sum('quantity'), (int) Product::query()->findOrFail($reservation->product_id)->stock);
        }
        $this->assertSame(1, PaymentTransaction::count());
        $this->assertSame(1, PaymentSubmissionHistory::where('payment_submission_id', $submission->id)->where('event', 'approved')->count());
    }

    public function transferChannels(): array
    {
        return [['yape'], ['plin'], ['bank_transfer']];
    }

    public function test_consistent_retry_returns_same_approval_without_new_history_or_inventory_effects(): void
    {
        [$submission, $order, $reservation, $inventory] = $this->fixture();
        Sanctum::actingAs(User::factory()->create(['role' => 'treasury']));
        $this->patchJson($this->url($submission))->assertOk();
        $before = [PaymentTransaction::firstOrFail()->id, PaymentSubmissionHistory::count(), InventoryMovement::count(), $inventory->fresh()->only(['quantity', 'reserved_quantity'])];

        $this->patchJson($this->url($submission))->assertOk()->assertJsonPath('data.transaction.id', $before[0]);

        $this->assertSame($before[1], PaymentSubmissionHistory::count());
        $this->assertSame($before[2], InventoryMovement::count());
        $this->assertSame($before[3], $inventory->fresh()->only(['quantity', 'reserved_quantity']));
        $this->assertSame(InventoryReservation::CONSUMED, $reservation->fresh()->status);
        $this->assertSame('approved', $order->fresh()->payment_status);
    }

    public function test_deleted_treasury_actor_is_tolerated_by_a_historic_coherent_retry(): void
    {
        [$submission, $order] = $this->fixture();
        $treasury = User::factory()->create(['role' => 'treasury']);
        Sanctum::actingAs($treasury);
        $this->patchJson($this->url($submission))->assertOk();
        $transaction = PaymentTransaction::firstOrFail();
        $historyCount = PaymentSubmissionHistory::count();
        $movementCount = InventoryMovement::count();
        $treasury->delete();
        $this->assertNull($transaction->fresh()->confirmed_by);
        $this->assertNull($submission->fresh()->reviewed_by);
        Sanctum::actingAs(User::factory()->create(['role' => 'treasury']));

        $this->patchJson($this->url($submission))->assertOk()->assertJsonPath('data.transaction.confirmed_by', null);
        $this->assertSame($historyCount, PaymentSubmissionHistory::count());
        $this->assertSame($movementCount, InventoryMovement::count());
        $this->assertSame('approved', $order->fresh()->payment_status);
    }

    public function test_approved_submission_without_confirmation_or_sale_movement_is_a_conflict_not_an_idempotent_retry(): void
    {
        [$submission, $order] = $this->fixture();
        Sanctum::actingAs(User::factory()->create(['role' => 'treasury']));
        $this->patchJson($this->url($submission))->assertOk();
        $transaction = PaymentTransaction::firstOrFail();
        $transaction->forceFill(['confirmed_at' => null])->saveQuietly();

        $this->patchJson($this->url($submission))->assertConflict()->assertJsonPath('code', 'payment_submission_approval_conflict');
        $this->assertSame(1, PaymentTransaction::count());
        $this->assertSame(PaymentSubmission::APPROVED, $submission->fresh()->status);
    }

    /**
     * @dataProvider inconsistent_approved_transaction_fields
     */
    public function test_each_incompatible_approved_transaction_field_is_a_safe_conflict(string $field, mixed $value): void
    {
        [$submission, $order] = $this->fixture();
        Sanctum::actingAs(User::factory()->create(['role' => 'treasury']));
        $this->patchJson($this->url($submission))->assertOk();
        $transaction = PaymentTransaction::firstOrFail();
        $transaction->forceFill([$field => $value])->saveQuietly();
        $before = $this->snapshot($submission, $order);

        $response = $this->patchJson($this->url($submission))->assertConflict()
            ->assertJsonPath('code', 'payment_submission_approval_conflict')
            ->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Pragma', 'no-cache');
        foreach (['sql', 'bindings', 'constraint', 'file', 'line', 'trace'] as $sensitive) {
            $this->assertStringNotContainsString($sensitive, strtolower($response->getContent()));
        }
        $this->assertSame($before, $this->snapshot($submission, $order));
    }

    public function inconsistent_approved_transaction_fields(): array
    {
        return [
            'amount' => ['amount', '19.99'],
            'currency' => ['currency', 'USD'],
            'status' => ['status', PaymentTransaction::PENDING],
            'payment method' => ['payment_method', 'card'],
            'transaction type' => ['transaction_type', PaymentTransaction::REFUND],
            'channel' => ['collection_method', 'plin'],
            'idempotency key' => ['idempotency_key', 'wrong-key'],
            'approved scope' => ['approved_scope_key', 'wrong-scope'],
        ];
    }

    /**
     * @dataProvider inconsistent_approved_submission_and_order_fields
     */
    public function test_each_incompatible_approved_submission_or_order_field_is_a_safe_conflict(string $model, string $field, mixed $value): void
    {
        [$submission, $order] = $this->fixture();
        Sanctum::actingAs(User::factory()->create(['role' => 'treasury']));
        $this->patchJson($this->url($submission))->assertOk();

        $target = ($model === 'submission' ? $submission : $order)->fresh();
        $target->forceFill([$field => $value])->saveQuietly();
        $this->assertSame($value, $target->fresh()->getAttribute($field));
        $before = $this->snapshot($submission, $order);

        $response = $this->patchJson($this->url($submission))->assertConflict()
            ->assertJsonPath('code', 'payment_submission_approval_conflict')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Pragma', 'no-cache');
        foreach (['sql', 'bindings', 'constraint', 'file', 'line', 'trace'] as $sensitive) {
            $this->assertStringNotContainsString($sensitive, strtolower($response->getContent()));
        }
        $this->assertSame($before, $this->snapshot($submission, $order));
    }

    public function inconsistent_approved_submission_and_order_fields(): array
    {
        return [
            'submission expected amount' => ['submission', 'expected_amount', '19.99'],
            'submission currency' => ['submission', 'currency', 'USD'],
            'order pending payment' => ['order', 'payment_status', 'pending'],
            'order rejected payment' => ['order', 'payment_status', 'rejected'],
            'order without paid timestamp' => ['order', 'paid_at', null],
        ];
    }

    /**
     * @dataProvider terminal_approved_order_states
     */
    public function test_an_approved_submission_on_a_terminal_order_is_a_safe_conflict(string $status, string $trackingStatus): void
    {
        [$submission, $order] = $this->fixture();
        Sanctum::actingAs(User::factory()->create(['role' => 'treasury']));
        $this->patchJson($this->url($submission))->assertOk();
        $order = $order->fresh();
        $order->forceFill(['status' => $status, 'tracking_status' => $trackingStatus])->saveQuietly();
        $before = $this->snapshot($submission, $order);

        $this->patchJson($this->url($submission))->assertConflict()
            ->assertJsonPath('code', 'payment_submission_approval_conflict')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Pragma', 'no-cache');
        $this->assertSame($before, $this->snapshot($submission, $order));
    }

    public function terminal_approved_order_states(): array
    {
        return [
            'canceled' => ['canceled', 'canceled'],
            'rejected' => ['rejected', 'pending'],
            'delivered' => ['delivered', 'delivered'],
            'picked up' => ['picked_up', 'picked_up'],
        ];
    }

    public function test_an_approved_submission_without_a_linked_transaction_is_a_safe_conflict(): void
    {
        [$submission, $order] = $this->fixture();
        Sanctum::actingAs(User::factory()->create(['role' => 'treasury']));
        $this->patchJson($this->url($submission))->assertOk();
        DB::table('payment_transactions')->where('payment_submission_id', $submission->id)->delete();
        $this->assertSame(0, PaymentTransaction::query()->where('payment_submission_id', $submission->id)->count());
        $before = $this->snapshot($submission, $order);

        $this->patchJson($this->url($submission))->assertConflict()
            ->assertJsonPath('code', 'payment_submission_approval_conflict')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Pragma', 'no-cache');
        $this->assertSame($before, $this->snapshot($submission, $order));
    }

    public function test_an_approved_legacy_or_gateway_transaction_for_the_same_order_blocks_treasury_without_mutation(): void
    {
        [$submission, $order] = $this->fixture();
        PaymentTransaction::forceCreate([
            'order_id' => $order->id,
            'payment_method' => 'card',
            'transaction_type' => PaymentTransaction::PAYMENT,
            'status' => PaymentTransaction::APPROVED,
            'amount' => $order->total,
            'currency' => 'PEN',
            'idempotency_key' => 'legacy-gateway-order-'.$order->id,
            // Existing historical card format: it must still block Treasury.
            'approved_scope_key' => 'card-approved-order-'.$order->id,
            'confirmed_at' => now(),
        ]);
        Sanctum::actingAs(User::factory()->create(['role' => 'treasury']));
        $before = $this->snapshot($submission, $order);

        $this->patchJson($this->url($submission))->assertConflict()
            ->assertJsonPath('code', 'payment_submission_approval_conflict')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Pragma', 'no-cache');
        $this->assertSame($before, $this->snapshot($submission, $order));
    }

    /**
     * @dataProvider treasury_approval_key_collisions
     */
    public function test_an_expected_treasury_key_occupied_by_another_transaction_is_a_safe_conflict(string $field): void
    {
        [$submission, $order] = $this->fixture();
        $key = $field === 'idempotency_key'
            ? 'treasury-approval-submission-'.$submission->id
            : PaymentTransaction::approvedScopeKeyForOrder($order->id);
        PaymentTransaction::forceCreate([
            'order_id' => $order->id,
            'payment_method' => 'transferencia',
            'transaction_type' => PaymentTransaction::PAYMENT,
            'status' => PaymentTransaction::PENDING,
            'amount' => $order->total,
            'currency' => 'PEN',
            'idempotency_key' => $field === 'idempotency_key' ? $key : 'unrelated-key-'.$order->id,
            'approved_scope_key' => $field === 'approved_scope_key' ? $key : null,
        ]);
        Sanctum::actingAs(User::factory()->create(['role' => 'treasury']));
        $before = $this->snapshot($submission, $order);

        $this->patchJson($this->url($submission))->assertConflict()
            ->assertJsonPath('code', 'payment_submission_approval_conflict')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Pragma', 'no-cache');
        $this->assertSame($before, $this->snapshot($submission, $order));
    }

    public function treasury_approval_key_collisions(): array
    {
        return [
            'idempotency key' => ['idempotency_key'],
            'approved scope key' => ['approved_scope_key'],
        ];
    }

    public function test_treasury_approved_scope_blocks_a_second_gateway_approval_for_the_same_order(): void
    {
        [$submission, $order] = $this->fixture();
        Sanctum::actingAs(User::factory()->create(['role' => 'treasury']));
        $this->patchJson($this->url($submission))->assertOk();
        $before = PaymentTransaction::count();

        $transaction = app(OrderPaymentService::class)->recordCardAttempt($order, 'gateway-retry-'.$order->id, PaymentTransaction::APPROVED);

        $this->assertSame($before, PaymentTransaction::count());
        $this->assertSame($submission->id, $transaction->payment_submission_id);
        $this->assertSame(PaymentTransaction::approvedScopeKeyForOrder($order->id), $transaction->approved_scope_key);
    }

    public function test_actor_matrix_hostile_payload_and_expired_reservation_are_rejected_without_mutation(): void
    {
        [$submission, $order, $reservation, $inventory] = $this->fixture();
        $url = $this->url($submission);
        $this->patchJson($url)->assertUnauthorized();
        Sanctum::actingAs(User::factory()->create(['role' => 'customer']));
        $this->patchJson($url)->assertForbidden();
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->patchJson($url)->assertForbidden();
        Sanctum::actingAs(User::factory()->create(['role' => 'treasury', 'is_active' => false]));
        $this->patchJson($url)->assertForbidden()->assertJsonPath('code', 'account_inactive');
        Sanctum::actingAs(User::factory()->create(['role' => 'treasury']));
        $this->patchJson($url, ['amount' => '0.01'])->assertUnprocessable();
        $order->update(['reserved_until' => now()->subSecond()]);
        $this->patchJson($url)->assertConflict()->assertJsonPath('code', 'manual_resolution_required');
        $this->assertSame(PaymentSubmission::PENDING_REVIEW, $submission->fresh()->status);
        $this->assertSame('pending', $order->fresh()->payment_status);
        $this->assertSame(InventoryReservation::ACTIVE, $reservation->fresh()->status);
        $this->assertSame(['quantity' => 2, 'reserved_quantity' => 1], $inventory->fresh()->only(['quantity', 'reserved_quantity']));
        $this->assertSame(0, PaymentTransaction::count());
    }

    /**
     * @dataProvider inventory_eligibility_inconsistencies
     */
    public function test_reservation_and_inventory_eligibility_inconsistencies_are_rejected_without_mutation(string $scenario, string $code): void
    {
        if (DB::connection()->getDriverName() === 'mysql' && in_array($scenario, ['negative_stock', 'negative_reserved'], true)) {
            $this->markTestSkipped('MySQL UNSIGNED inventory columns prevent persisting this SQLite-only corrupt fixture.');
        }
        [$submission, $order, $reservation, $inventory, $product] = $this->fixture();
        $this->applyEligibilityInconsistency($scenario, $order, $reservation, $inventory, $product);
        Sanctum::actingAs(User::factory()->create(['role' => 'treasury']));
        $before = $this->snapshot($submission, $order);

        $response = $this->patchJson($this->url($submission))->assertConflict()
            ->assertJsonPath('code', $code)
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Pragma', 'no-cache');
        foreach (['exception', 'trace', 'file', 'line', 'sql', 'bindings', 'constraint'] as $sensitive) {
            $this->assertStringNotContainsString($sensitive, strtolower($response->getContent()));
        }
        $this->assertSame($before, $this->snapshot($submission, $order));
    }

    public function inventory_eligibility_inconsistencies(): array
    {
        return [
            'no reservations' => ['no_reservations', 'manual_resolution_required'],
            'released reservation' => ['released', 'manual_resolution_required'],
            'expired reservation' => ['expired', 'manual_resolution_required'],
            'already consumed reservation' => ['consumed', 'manual_resolution_required'],
            'expired reservation timestamp' => ['reservation_expired_at', 'manual_resolution_required'],
            'expired order reservation deadline' => ['order_expired_at', 'manual_resolution_required'],
            'reservation quantity mismatch' => ['quantity_mismatch', 'payment_inventory_conflict'],
            'missing warehouse inventory' => ['missing_inventory', 'payment_inventory_conflict'],
            'insufficient reserved balance' => ['reserved_shortfall', 'payment_inventory_conflict'],
            'insufficient physical balance' => ['physical_shortfall', 'payment_inventory_conflict'],
            'negative physical balance' => ['negative_stock', 'payment_inventory_conflict'],
            'negative reserved balance' => ['negative_reserved', 'payment_inventory_conflict'],
            'product stock desynchronized' => ['product_stock_mismatch', 'payment_inventory_conflict'],
            'extra reservation without item' => ['extra_reservation', 'payment_inventory_conflict'],
        ];
    }

    /**
     * @dataProvider approved_reservation_inconsistencies
     */
    public function test_approved_retry_with_inconsistent_reservations_is_a_safe_conflict(string $scenario): void
    {
        [$submission, $order] = $this->fixture();
        Sanctum::actingAs(User::factory()->create(['role' => 'treasury']));
        $this->patchJson($this->url($submission))->assertOk();
        $this->applyApprovedReservationInconsistency($scenario, $order);
        $before = $this->snapshot($submission, $order);

        $this->patchJson($this->url($submission))->assertConflict()
            ->assertJsonPath('code', 'payment_submission_approval_conflict')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Pragma', 'no-cache');
        $this->assertSame($before, $this->snapshot($submission, $order));
    }

    public function approved_reservation_inconsistencies(): array
    {
        return [
            'reservation reverted to active' => ['active'],
            'consumed reservation without timestamp' => ['missing_consumed_at'],
            'active reservation with consumed timestamp' => ['active_with_consumed_at'],
            'consumed reservation quantity altered' => ['quantity_altered'],
            'released reservation after approval' => ['released'],
            'extra unexpected reservation after approval' => ['extra'],
        ];
    }

    /**
     * @dataProvider approved_sale_movement_inconsistencies
     */
    public function test_approved_retry_with_inconsistent_sale_movement_is_a_safe_conflict(string $scenario): void
    {
        [$submission, $order, $reservation] = $this->fixture();
        Sanctum::actingAs(User::factory()->create(['role' => 'treasury']));
        $this->patchJson($this->url($submission))->assertOk();
        $movement = InventoryMovement::query()->where('idempotency_key', 'sale-order-item-'.$reservation->order_item_id)->firstOrFail();
        $this->applySaleMovementInconsistency($scenario, $movement, $order);
        $before = $this->snapshot($submission, $order);

        $this->patchJson($this->url($submission))->assertConflict()
            ->assertJsonPath('code', 'payment_submission_approval_conflict')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Pragma', 'no-cache');
        $this->assertSame($before, $this->snapshot($submission, $order));
    }

    public function approved_sale_movement_inconsistencies(): array
    {
        return [
            'expected movement absent' => ['missing'],
            'duplicate sale movement' => ['duplicate'],
            'wrong movement type' => ['type'],
            'wrong order reference' => ['reference'],
            'wrong product' => ['product'],
            'wrong warehouse' => ['warehouse'],
            'wrong quantity' => ['quantity'],
            'wrong sale direction' => ['direction'],
        ];
    }

    public function test_legacy_transfer_approval_is_blocked_when_a_submission_exists(): void
    {
        [$submission, $order] = $this->fixture();
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->postJson('/api/v1/admin/orders/'.$order->id.'/fulfillment/approve-transfer')
            ->assertConflict()->assertJsonPath('code', 'treasury_approval_required');
        $this->assertSame(PaymentSubmission::PENDING_REVIEW, $submission->fresh()->status);
        $this->assertSame('pending', $order->fresh()->payment_status);
        $this->assertSame(0, PaymentTransaction::count());
    }

    /**
     * @dataProvider non_approvable_submission_statuses
     */
    public function test_terminal_or_observed_submission_is_never_approved(string $status): void
    {
        [$submission, $order, $reservation, $inventory] = $this->fixture();
        $submission->update(['status' => $status]);
        Sanctum::actingAs(User::factory()->create(['role' => 'treasury']));

        $this->patchJson($this->url($submission))->assertConflict();
        $this->assertSame($status, $submission->fresh()->status);
        $this->assertSame('pending', $order->fresh()->payment_status);
        $this->assertSame(InventoryReservation::ACTIVE, $reservation->fresh()->status);
        $this->assertSame(['quantity' => 2, 'reserved_quantity' => 1], $inventory->fresh()->only(['quantity', 'reserved_quantity']));
        $this->assertSame(0, PaymentTransaction::count());
    }

    public function non_approvable_submission_statuses(): array
    {
        return [[PaymentSubmission::OBSERVED], [PaymentSubmission::REJECTED], [PaymentSubmission::EXPIRED], [PaymentSubmission::CANCELED]];
    }

    public function test_partial_financial_state_returns_a_conflict_without_repairing_it(): void
    {
        [$submission, $order, $reservation] = $this->fixture();
        PaymentTransaction::forceCreate([
            'order_id' => $order->id,
            'payment_submission_id' => $submission->id,
            'payment_method' => 'transferencia',
            'transaction_type' => 'payment',
            'status' => 'pending',
            'amount' => '20.00',
            'currency' => 'PEN',
            'idempotency_key' => 'partial-'.$submission->id,
        ]);
        Sanctum::actingAs(User::factory()->create(['role' => 'treasury']));

        $response = $this->patchJson($this->url($submission))->assertConflict();
        foreach (['exception', 'trace', 'file', 'line', 'sql', 'bindings'] as $sensitive) {
            $this->assertStringNotContainsString($sensitive, strtolower($response->getContent()));
        }
        $this->assertSame(PaymentSubmission::PENDING_REVIEW, $submission->fresh()->status);
        $this->assertSame(InventoryReservation::ACTIVE, $reservation->fresh()->status);
        $this->assertSame('pending', $order->fresh()->payment_status);
    }

    /**
     * SQLite triggers fail at the same physical INSERT/UPDATE used in production.
     *
     * @dataProvider approval_write_failure_points
     */
    public function test_database_write_failures_rollback_the_outer_approval_transaction(string $table, string $operation): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Uses SQLite RAISE(ABORT) triggers; physical MySQL races cover transactional rollback.');
        }
        [$submission, $order] = $this->fixture();
        $before = $this->snapshot($submission, $order);
        $trigger = 'approval_rollback_'.str_replace('_', '', $table).$operation;
        DB::unprepared("CREATE TRIGGER {$trigger} BEFORE {$operation} ON {$table} BEGIN SELECT RAISE(ABORT, 'approval failure'); END;");

        try {
            app(TreasuryPaymentApprovalService::class)->approve($submission->id, User::factory()->create(['role' => 'treasury'])->id);
            $this->fail('La aprobación debía fallar por el trigger SQLite.');
        } catch (\Throwable $exception) {
            $this->assertNotInstanceOf(\Illuminate\Http\Exceptions\HttpResponseException::class, $exception);
        } finally {
            DB::unprepared("DROP TRIGGER IF EXISTS {$trigger}");
        }

        $this->assertSame($before, $this->snapshot($submission, $order));
    }

    public function approval_write_failure_points(): array
    {
        return [
            ['payment_transactions', 'INSERT'],
            ['payment_submissions', 'UPDATE'],
            ['payment_submission_histories', 'INSERT'],
            ['orders', 'UPDATE'],
            ['inventory_reservations', 'UPDATE'],
            ['warehouse_inventories', 'UPDATE'],
            ['inventory_movements', 'INSERT'],
            ['products', 'UPDATE'],
        ];
    }

    public function test_failure_entering_consume_order_reservation_rolls_back_prior_financial_writes(): void
    {
        [$submission, $order] = $this->fixture();
        $before = $this->snapshot($submission, $order);
        $inventory = Mockery::mock(InventoryService::class);
        $inventory->shouldReceive('consumeOrderReservation')->once()->andThrow(new \RuntimeException('injected consume entry failure'));

        try {
            (new TreasuryPaymentApprovalService($inventory, app(TreasuryPaymentService::class)))->approve($submission->id, User::factory()->create(['role' => 'treasury'])->id);
            $this->fail('El consumo debía fallar antes de mutar inventario.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('injected consume entry failure', $exception->getMessage());
        }

        $this->assertSame($before, $this->snapshot($submission, $order));
    }

    public function test_failure_after_nested_consumption_rolls_back_the_completed_inner_transaction(): void
    {
        [$submission, $order] = $this->fixture();
        $before = $this->snapshot($submission, $order);
        $realInventory = app(InventoryService::class);
        $inventory = Mockery::mock($realInventory)->makePartial();
        $inventory->shouldReceive('consumeOrderReservation')->once()->andReturnUsing(function (...$arguments) use ($realInventory) {
            $realInventory->consumeOrderReservation(...$arguments);
            throw new \RuntimeException('injected outer failure after nested consumption');
        });

        try {
            (new TreasuryPaymentApprovalService($inventory, app(TreasuryPaymentService::class)))->approve($submission->id, User::factory()->create(['role' => 'treasury'])->id);
            $this->fail('La transacción exterior debía fallar después del consumo anidado.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('injected outer failure after nested consumption', $exception->getMessage());
        }

        $this->assertSame($before, $this->snapshot($submission, $order));
    }

    public function test_second_reservation_trigger_rolls_back_the_first_completed_reservation_and_inventory_mutation(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Uses a SQLite RAISE(ABORT) trigger; physical MySQL races cover partial-consumption rollback.');
        }
        [$submission, $order] = $this->fixture();
        $this->addSecondReservedItem($order);
        $before = $this->snapshot($submission, $order);
        $second = InventoryReservation::query()->where('order_id', $order->id)->orderBy('id')->skip(1)->firstOrFail();
        DB::unprepared("CREATE TRIGGER approval_second_reservation_failure BEFORE UPDATE ON inventory_reservations WHEN NEW.id = {$second->id} BEGIN SELECT RAISE(ABORT, 'second reservation failure'); END;");

        try {
            app(TreasuryPaymentApprovalService::class)->approve($submission->id, User::factory()->create(['role' => 'treasury'])->id);
            $this->fail('La segunda reserva debía fallar después de modificar la primera.');
        } catch (\Throwable $exception) {
            $this->assertNotInstanceOf(\Illuminate\Http\Exceptions\HttpResponseException::class, $exception);
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS approval_second_reservation_failure');
        }

        $this->assertSame($before, $this->snapshot($submission, $order));
    }

    /**
     * @dataProvider approvalActors
     */
    public function test_approval_authorization_rejections_are_safe(string $role, bool $active, int $status, ?string $code): void
    {
        [$submission, $order] = $this->fixture();
        $before = $this->snapshot($submission, $order);
        Sanctum::actingAs(User::factory()->create(['role' => $role, 'is_active' => $active]));

        $response = $this->patchJson($this->url($submission))->assertStatus($status);
        if ($code !== null) {
            $response->assertJsonPath('code', $code);
        }
        $this->assertSame($before, $this->snapshot($submission, $order));
    }

    public function approvalActors(): array
    {
        return [
            'customer active' => ['customer', true, 403, null],
            'admin active' => ['admin', true, 403, null],
            'treasury inactive' => ['treasury', false, 403, 'account_inactive'],
        ];
    }

    public function test_anonymous_approval_rejection_is_safe(): void
    {
        [$submission, $order] = $this->fixture();
        $before = $this->snapshot($submission, $order);

        $this->patchJson($this->url($submission))->assertUnauthorized();

        $this->assertSame($before, $this->snapshot($submission, $order));
    }

    public function test_active_treasury_approves_and_missing_submission_is_a_private_404(): void
    {
        [$submission] = $this->fixture();
        Sanctum::actingAs(User::factory()->create(['role' => 'treasury']));

        $this->patchJson($this->url($submission))->assertOk();
        $response = $this->patchJson('/api/v1/treasury/payment-submissions/999999/approve')
            ->assertNotFound()
            ->assertJsonPath('code', 'payment_submission_not_found')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Pragma', 'no-cache');
        foreach (['exception', 'trace', 'file', 'line', 'sql'] as $sensitive) {
            $this->assertStringNotContainsString($sensitive, strtolower($response->getContent()));
        }
    }

    /**
     * @dataProvider forbiddenApprovalBodies
     */
    public function test_approval_accepts_only_an_empty_body(array $body): void
    {
        [$submission, $order] = $this->fixture();
        Sanctum::actingAs(User::factory()->create(['role' => 'treasury']));
        $before = $this->snapshot($submission, $order);

        $response = $this->patchJson($this->url($submission), $body)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('request');
        foreach (['exception', 'trace', 'file', 'line', 'sql', 'bindings', 'constraint'] as $sensitive) {
            $this->assertStringNotContainsString($sensitive, strtolower($response->getContent()));
        }
        $this->assertSame($before, $this->snapshot($submission, $order));
    }

    public function forbiddenApprovalBodies(): array
    {
        $fields = ['amount', 'currency', 'status', 'order_id', 'payment_submission_id', 'transaction_id', 'payment_status', 'paid_at', 'confirmed_by', 'confirmed_at', 'reviewed_by', 'reviewed_at', 'idempotency_key', 'approved_scope_key', 'operation_number', 'fingerprint', 'snapshot', 'metadata', 'reservation', 'inventory', 'foo'];
        $cases = [];
        foreach ($fields as $field) {
            $cases[$field] = [[$field => 'hostile']];
        }
        $cases['nested object'] = [['foo' => ['nested' => 'hostile']]];
        $cases['array'] = [['foo' => ['hostile']]];

        return $cases;
    }

    public function test_approval_empty_body_ignores_route_and_query_parameters_but_returns_only_safe_resource_fields(): void
    {
        [$submission] = $this->fixture();
        Sanctum::actingAs(User::factory()->create(['role' => 'treasury']));

        $response = $this->patchJson($this->url($submission).'?technical=1', [])
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Pragma', 'no-cache');
        foreach (['operation_number', 'normalized_operation', 'origin_phone', 'origin_bank', 'fingerprint', 'receiving_account_snapshot', 'idempotency_key', 'approved_scope_key', 'metadata', 'qr_path', 'qr_disk', 'exception', 'trace', 'file', 'line', 'sql', 'bindings', 'constraint'] as $sensitive) {
            $this->assertStringNotContainsString($sensitive, strtolower($response->getContent()));
        }
    }

    /**
     * @dataProvider legacySubmissionStatuses
     */
    public function test_legacy_approve_transfer_is_blocked_for_every_payment_submission_status(string $status): void
    {
        [$submission, $order] = $this->fixture();
        $submission->update(['status' => $status]);
        $before = $this->snapshot($submission, $order);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        $this->postJson('/api/v1/admin/orders/'.$order->id.'/fulfillment/approve-transfer')
            ->assertConflict()
            ->assertJsonPath('code', 'treasury_approval_required');
        $this->assertSame($before, $this->snapshot($submission, $order));
    }

    public function legacySubmissionStatuses(): array
    {
        return array_map(fn (string $status) => [$status], [
            PaymentSubmission::PENDING_REVIEW,
            PaymentSubmission::OBSERVED,
            PaymentSubmission::REJECTED,
            PaymentSubmission::APPROVED,
            PaymentSubmission::EXPIRED,
            PaymentSubmission::CANCELED,
        ]);
    }

    private function url(PaymentSubmission $submission): string
    {
        return '/api/v1/treasury/payment-submissions/'.$submission->id.'/approve';
    }

    private function fixture(string $channel = 'yape'): array
    {
        $owner = User::factory()->create();
        $order = Order::create(['user_id' => $owner->id, 'status' => 'pending', 'total' => '20.00', 'shipping_info' => ['recipient_name' => 'Cliente'], 'payment_method' => 'transferencia', 'payment_status' => 'pending', 'reserved_until' => now()->addHour(), 'delivery_type' => 'delivery', 'tracking_status' => 'pending', 'fulfillment_status' => 'reserved']);
        $branch = Branch::create(['code' => 'B1', 'name' => 'Sede', 'address' => 'Dirección', 'is_active' => true]);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'code' => 'W1', 'name' => 'Almacén', 'is_active' => true, 'is_default' => false]);
        $product = Product::create(['name' => 'Producto', 'slug' => 'producto', 'sku' => 'SKU-1', 'price' => '20.00', 'sale_price' => '20.00', 'stock' => 2, 'is_active' => true]);
        $inventory = WarehouseInventory::create(['warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'quantity' => 2, 'reserved_quantity' => 1]);
        Product::query()->whereKey($product->id)->update(['stock' => 2]);
        $item = $order->items()->create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'quantity' => 1, 'price' => '20.00', 'subtotal' => '20.00']);
        $reservation = InventoryReservation::create(['order_id' => $order->id, 'order_item_id' => $item->id, 'product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'quantity' => 1, 'status' => InventoryReservation::ACTIVE, 'expires_at' => now()->addHour(), 'idempotency_key' => 'reservation-'.$order->id]);
        $submission = PaymentSubmission::create(['order_id' => $order->id, 'channel' => $channel, 'status' => PaymentSubmission::PENDING_REVIEW, 'expected_amount' => '20.00', 'currency' => 'PEN', 'operation_number' => '000123', 'normalized_operation_number' => '000123', 'duplicate_fingerprint' => hash('sha256', 'fp-'.$order->id), 'declared_paid_at' => now(), 'origin_phone_last4' => $channel === 'bank_transfer' ? null : '1234', 'origin_bank' => $channel === 'bank_transfer' ? 'Banco de prueba' : null, 'receiving_account_snapshot' => ['id' => 1], 'submitted_by' => $owner->id, 'submitted_at' => now(), 'idempotency_key' => 'submission-'.$order->id]);

        return [$submission, $order, $reservation, $inventory, $product];
    }

    private function snapshot(PaymentSubmission $submission, Order $order): array
    {
        return [
            'submission' => array_merge($submission->fresh()->only(['status', 'reviewed_by', 'decision_reason']), ['reviewed_at' => $submission->fresh()->reviewed_at?->toIso8601String()]),
            'histories' => PaymentSubmissionHistory::query()->where('payment_submission_id', $submission->id)->orderBy('id')->get()->map(fn ($history) => array_merge($history->only(['event', 'from_status', 'to_status', 'actor_id', 'reason']), ['occurred_at' => $history->occurred_at?->toIso8601String()]))->all(),
            'transactions' => PaymentTransaction::query()->where('order_id', $order->id)->orderBy('id')->get()->map(fn ($transaction) => $transaction->only(['payment_submission_id', 'status', 'amount', 'currency']))->all(),
            'order' => array_merge($order->fresh()->only(['status', 'fulfillment_status', 'payment_status']), ['paid_at' => $order->fresh()->paid_at?->toIso8601String()]),
            'reservations' => InventoryReservation::query()->where('order_id', $order->id)->orderBy('id')->get()->map(fn ($reservation) => array_merge($reservation->only(['status', 'quantity']), ['expires_at' => $reservation->expires_at?->toIso8601String(), 'consumed_at' => $reservation->consumed_at?->toIso8601String(), 'released_at' => $reservation->released_at?->toIso8601String()]))->all(),
            'inventories' => WarehouseInventory::query()->whereIn('warehouse_id', InventoryReservation::query()->where('order_id', $order->id)->pluck('warehouse_id'))->orderBy('id')->get()->map(fn ($inventory) => $inventory->only(['quantity', 'reserved_quantity']))->all(),
            'products' => Product::query()->whereIn('id', $order->items()->pluck('product_id'))->orderBy('id')->pluck('stock')->all(),
            'items' => $order->items()->orderBy('id')->pluck('quantity')->all(),
            'movements' => InventoryMovement::query()->where('reference_id', (string) $order->id)->orderBy('id')->get()->map(fn ($movement) => $movement->only(['type', 'quantity', 'quantity_before', 'quantity_after']))->all(),
        ];
    }

    private function addSecondReservedItem(Order $order): void
    {
        $warehouse = Warehouse::create(['branch_id' => Branch::firstOrFail()->id, 'code' => 'W2', 'name' => 'Almacén dos', 'is_active' => true, 'is_default' => false]);
        $product = Product::create(['name' => 'Producto dos', 'slug' => 'producto-dos', 'sku' => 'SKU-2', 'price' => '10.00', 'sale_price' => '10.00', 'stock' => 3, 'is_active' => true]);
        $inventory = WarehouseInventory::create(['warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'quantity' => 3, 'reserved_quantity' => 1]);
        Product::query()->whereKey($product->id)->update(['stock' => 3]);
        $item = $order->items()->create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'quantity' => 1, 'price' => '10.00', 'subtotal' => '10.00']);
        InventoryReservation::create(['order_id' => $order->id, 'order_item_id' => $item->id, 'product_id' => $product->id, 'warehouse_id' => $inventory->warehouse_id, 'quantity' => 1, 'status' => InventoryReservation::ACTIVE, 'expires_at' => now()->addHour(), 'idempotency_key' => 'reservation-extra-'.$order->id]);
        $order->update(['total' => '30.00']);
        PaymentSubmission::query()->where('order_id', $order->id)->update(['expected_amount' => '30.00']);
    }

    private function applyEligibilityInconsistency(string $scenario, Order $order, InventoryReservation $reservation, WarehouseInventory $inventory, Product $product): void
    {
        match ($scenario) {
            'no_reservations' => DB::table('inventory_reservations')->where('id', $reservation->id)->delete(),
            'released' => $reservation->update(['status' => InventoryReservation::RELEASED]),
            'expired' => $reservation->update(['status' => InventoryReservation::EXPIRED]),
            'consumed' => $reservation->update(['status' => InventoryReservation::CONSUMED, 'consumed_at' => now()]),
            'reservation_expired_at' => $reservation->update(['expires_at' => now()->subSecond()]),
            'order_expired_at' => $order->update(['reserved_until' => now()->subSecond()]),
            'quantity_mismatch' => $reservation->update(['quantity' => 2]),
            'missing_inventory' => DB::table('warehouse_inventories')->where('id', $inventory->id)->delete(),
            'reserved_shortfall' => $inventory->update(['reserved_quantity' => 0]),
            'physical_shortfall' => $inventory->update(['quantity' => 0]),
            'negative_stock' => $inventory->update(['quantity' => -1]),
            'negative_reserved' => $inventory->update(['reserved_quantity' => -1]),
            'product_stock_mismatch' => Product::query()->whereKey($product->id)->update(['stock' => 99]),
            'extra_reservation' => $this->addReservationForAnotherOrderItem($order, $reservation),
        };
    }

    private function addReservationForAnotherOrderItem(Order $order, InventoryReservation $reservation): void
    {
        $otherOrder = Order::create([
            'user_id' => $order->user_id,
            'status' => 'pending',
            'total' => '20.00',
            'shipping_info' => ['recipient_name' => 'Otro cliente'],
            'payment_method' => 'transferencia',
            'payment_status' => 'pending',
            'reserved_until' => now()->addHour(),
            'delivery_type' => 'delivery',
            'tracking_status' => 'pending',
            'fulfillment_status' => 'reserved',
        ]);
        $foreignItem = $otherOrder->items()->create([
            'product_id' => $reservation->product_id,
            'warehouse_id' => $reservation->warehouse_id,
            'quantity' => $reservation->quantity,
            'price' => '20.00',
            'subtotal' => '20.00',
        ]);
        InventoryReservation::create([
            'order_id' => $order->id,
            'order_item_id' => $foreignItem->id,
            'product_id' => $reservation->product_id,
            'warehouse_id' => $reservation->warehouse_id,
            'quantity' => $reservation->quantity,
            'status' => InventoryReservation::ACTIVE,
            'expires_at' => now()->addHour(),
            'idempotency_key' => 'unexpected-reservation-'.$order->id,
        ]);
    }

    private function applyApprovedReservationInconsistency(string $scenario, Order $order): void
    {
        $reservation = InventoryReservation::query()->where('order_id', $order->id)->firstOrFail();
        match ($scenario) {
            'active' => DB::table('inventory_reservations')->where('id', $reservation->id)->update(['status' => InventoryReservation::ACTIVE, 'consumed_at' => null]),
            'missing_consumed_at' => DB::table('inventory_reservations')->where('id', $reservation->id)->update(['consumed_at' => null]),
            'active_with_consumed_at' => DB::table('inventory_reservations')->where('id', $reservation->id)->update(['status' => InventoryReservation::ACTIVE, 'consumed_at' => now()]),
            'quantity_altered' => DB::table('inventory_reservations')->where('id', $reservation->id)->update(['quantity' => 2]),
            'released' => DB::table('inventory_reservations')->where('id', $reservation->id)->update(['status' => InventoryReservation::RELEASED]),
            'extra' => $this->addReservationForAnotherOrderItem($order, $reservation),
        };
    }

    private function applySaleMovementInconsistency(string $scenario, InventoryMovement $movement, Order $order): void
    {
        if ($scenario === 'missing') {
            DB::table('inventory_movements')->where('id', $movement->id)->delete();

            return;
        }
        if ($scenario === 'duplicate') {
            DB::table('inventory_movements')->insert([
                'warehouse_id' => $movement->warehouse_id,
                'product_id' => $movement->product_id,
                'user_id' => $movement->user_id,
                'type' => $movement->type,
                'quantity' => $movement->quantity,
                'quantity_before' => $movement->quantity_before,
                'quantity_after' => $movement->quantity_after,
                'reason' => $movement->reason,
                'reference_type' => $movement->reference_type,
                'reference_id' => $movement->reference_id,
                'idempotency_key' => 'duplicate-sale-'.$movement->id,
                'metadata' => json_encode($movement->metadata ?? []),
                'created_at' => now(),
            ]);

            return;
        }
        $changes = match ($scenario) {
            'type' => ['type' => InventoryMovement::MANUAL_OUT],
            'reference' => ['reference_id' => (string) ($order->id + 999)],
            'product' => ['product_id' => Product::create(['name' => 'Producto ajeno', 'slug' => 'producto-ajeno-'.$movement->id, 'sku' => 'SKU-AJENO-'.$movement->id, 'price' => '1.00', 'sale_price' => '1.00', 'stock' => 0, 'is_active' => true])->id],
            'warehouse' => ['warehouse_id' => Warehouse::create(['branch_id' => Branch::firstOrFail()->id, 'code' => 'W-AJENO-'.$movement->id, 'name' => 'Almacén ajeno', 'is_active' => true, 'is_default' => false])->id],
            'quantity' => ['quantity' => 2],
            'direction' => ['quantity_after' => $movement->quantity_before],
        };
        DB::table('inventory_movements')->where('id', $movement->id)->update($changes);
    }
}
