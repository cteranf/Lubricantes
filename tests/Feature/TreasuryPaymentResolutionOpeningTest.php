<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\InventoryMovement;
use App\Models\InventoryReservation;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentResolutionCase;
use App\Models\PaymentResolutionHistory;
use App\Models\PaymentSubmission;
use App\Models\PaymentSubmissionHistory;
use App\Models\PaymentTransaction;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseInventory;
use App\Services\TreasuryPaymentResolutionService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TreasuryPaymentResolutionOpeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_treasury_opens_a_resolution_for_an_expired_pending_submission_without_operational_mutations(): void
    {
        [$treasury, $order, $submission, $reservation, $inventory, $product, $item] = $this->fixture(InventoryReservation::EXPIRED);
        $before = $this->operationalState($order, $reservation, $inventory, $product, $item);

        $response = $this->actingAs($treasury, 'sanctum')->postJson($this->url($submission));

        $response->assertOk()
            ->assertJsonPath('data.resolution.status', PaymentResolutionCase::OPEN)
            ->assertJsonPath('data.resolution.type', null)
            ->assertJsonPath('data.submission.id', $submission->id)
            ->assertJsonPath('data.submission.status', PaymentSubmission::MANUAL_RESOLUTION_REQUIRED)
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Pragma', 'no-cache');
        $response->assertJsonMissingPath('data.transaction.idempotency_key')
            ->assertJsonMissingPath('data.submission.operation_number');

        $case = PaymentResolutionCase::firstOrFail();
        $transaction = PaymentTransaction::where('payment_submission_id', $submission->id)->firstOrFail();
        $this->assertSame(PaymentTransaction::RECEIVED, $transaction->status);
        $this->assertNull($transaction->approved_scope_key);
        $this->assertSame('12.50', $transaction->amount);
        $this->assertSame($transaction->id, $case->received_payment_transaction_id);
        $this->assertNull($case->type);
        $this->assertSame(PaymentResolutionCase::OPEN, $case->status);
        $this->assertSame(PaymentSubmission::MANUAL_RESOLUTION_REQUIRED, $submission->fresh()->status);
        $this->assertSame(1, PaymentSubmissionHistory::where('payment_submission_id', $submission->id)->where('event', 'resolution_opened')->count());
        $this->assertSame(1, PaymentResolutionHistory::where('payment_resolution_case_id', $case->id)->where('event', 'opened')->count());
        $this->assertSame($before, $this->operationalState($order, $reservation, $inventory, $product, $item));
    }

    public function test_observed_released_reservation_on_a_non_canceled_order_can_open_without_reopening_the_order(): void
    {
        [$treasury, $order, $submission, $reservation] = $this->fixture(InventoryReservation::RELEASED, PaymentSubmission::OBSERVED);
        $before = [$order->status, $order->payment_status, $order->paid_at?->toIso8601String(), $reservation->fresh()->status];

        $this->actingAs($treasury, 'sanctum')->postJson($this->url($submission))->assertOk();

        $this->assertSame($before, [$order->fresh()->status, $order->fresh()->payment_status, $order->fresh()->paid_at?->toIso8601String(), $reservation->fresh()->status]);
        $this->assertSame(PaymentSubmission::MANUAL_RESOLUTION_REQUIRED, $submission->fresh()->status);
    }

    public function test_canceled_order_with_an_expired_reservation_can_open_a_resolution_without_reopening_the_order(): void
    {
        [$treasury, $order, $submission, $reservation] = $this->fixture(InventoryReservation::EXPIRED, PaymentSubmission::OBSERVED);
        $order->update(['status' => 'canceled']);

        $this->actingAs($treasury, 'sanctum')->postJson($this->url($submission))->assertOk();

        $this->assertSame('canceled', $order->fresh()->status);
        $this->assertSame(InventoryReservation::EXPIRED, $reservation->fresh()->status);
        $this->assertSame(PaymentSubmission::MANUAL_RESOLUTION_REQUIRED, $submission->fresh()->status);
    }

    public function test_canceled_order_with_a_manually_released_reservation_is_rejected_as_ambiguous(): void
    {
        [$treasury, $order, $submission, $reservation, $inventory, $product, $item] = $this->fixture(InventoryReservation::RELEASED, PaymentSubmission::OBSERVED);
        $order->update(['status' => 'canceled']);
        $before = $this->operationalState($order, $reservation, $inventory, $product, $item);

        $this->actingAs($treasury, 'sanctum')->postJson($this->url($submission))
            ->assertConflict()
            ->assertJsonPath('code', 'payment_resolution_conflict');

        $this->assertSame(0, PaymentResolutionCase::count());
        $this->assertSame(0, PaymentTransaction::count());
        $this->assertSame(PaymentSubmission::OBSERVED, $submission->fresh()->status);
        $this->assertSame($before, $this->operationalState($order, $reservation, $inventory, $product, $item));
    }

    public function test_canceled_order_with_released_reservation_and_past_expiration_data_is_still_rejected_as_ambiguous(): void
    {
        [$treasury, $order, $submission, $reservation] = $this->fixture(InventoryReservation::RELEASED, PaymentSubmission::OBSERVED);
        $order->update(['status' => 'canceled']);
        $this->assertTrue($reservation->expires_at->isPast());
        $this->assertNotNull($reservation->released_at);

        $this->actingAs($treasury, 'sanctum')->postJson($this->url($submission))
            ->assertConflict()
            ->assertJsonPath('code', 'payment_resolution_conflict');

        $this->assertSame(0, PaymentResolutionCase::count());
        $this->assertSame(0, PaymentTransaction::count());
    }

    public function test_active_valid_reservation_is_a_conflict_and_uses_no_resolution_side_effects(): void
    {
        [$treasury, $order, $submission] = $this->fixture(InventoryReservation::ACTIVE, PaymentSubmission::PENDING_REVIEW, true);

        $this->actingAs($treasury, 'sanctum')->postJson($this->url($submission))
            ->assertConflict()
            ->assertJsonPath('code', 'payment_resolution_conflict')
            ->assertHeader('Cache-Control', 'no-store, private');

        $this->assertSame(0, PaymentResolutionCase::count());
        $this->assertSame(0, PaymentTransaction::count());
        $this->assertSame(PaymentSubmission::PENDING_REVIEW, $submission->fresh()->status);
        $this->assertSame('pending', $order->fresh()->payment_status);
    }

    public function test_active_expired_reservation_can_open_a_resolution_without_consuming_inventory(): void
    {
        [$treasury, $order, $submission, $reservation, $inventory, $product, $item] = $this->fixture(InventoryReservation::ACTIVE);
        $before = $this->operationalState($order, $reservation, $inventory, $product, $item);

        $this->actingAs($treasury, 'sanctum')->postJson($this->url($submission))->assertOk();

        $this->assertSame(PaymentSubmission::MANUAL_RESOLUTION_REQUIRED, $submission->fresh()->status);
        $this->assertSame($before, $this->operationalState($order, $reservation, $inventory, $product, $item));
    }

    public function test_consumed_reservation_cannot_open_a_resolution(): void
    {
        [$treasury, , $submission] = $this->fixture(InventoryReservation::CONSUMED);

        $this->actingAs($treasury, 'sanctum')->postJson($this->url($submission))
            ->assertConflict()
            ->assertJsonPath('code', 'payment_resolution_conflict');

        $this->assertSame(0, PaymentResolutionCase::count());
        $this->assertSame(0, PaymentTransaction::count());
    }

    public function test_actor_matrix_and_missing_submission_are_safe(): void
    {
        [$treasury, , $submission] = $this->fixture(InventoryReservation::EXPIRED);
        $customer = User::factory()->create(['role' => 'customer', 'is_active' => true]);
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $inactive = User::factory()->create(['role' => 'treasury', 'is_active' => false]);

        $this->postJson($this->url($submission))->assertUnauthorized();
        $this->actingAs($customer, 'sanctum')->postJson($this->url($submission))->assertForbidden();
        $this->actingAs($admin, 'sanctum')->postJson($this->url($submission))->assertForbidden();
        $this->actingAs($inactive, 'sanctum')->postJson($this->url($submission))->assertForbidden()->assertJsonPath('code', 'account_inactive');
        $this->actingAs($treasury, 'sanctum')->postJson('/api/v1/treasury/payment-submissions/999999/resolution')
            ->assertNotFound()
            ->assertJsonPath('code', 'payment_submission_not_found')
            ->assertJsonMissingPath('exception');
    }

    public function test_empty_body_is_required_and_a_retry_is_idempotent_without_extra_writes(): void
    {
        [$treasury, $order, $submission, $reservation, $inventory, $product, $item] = $this->fixture(InventoryReservation::EXPIRED);
        $this->actingAs($treasury, 'sanctum')->postJson($this->url($submission), ['amount' => '0.01'])->assertUnprocessable();
        $this->actingAs($treasury, 'sanctum')->postJson($this->url($submission), ['metadata' => ['x' => 'y']])->assertUnprocessable();
        $this->actingAs($treasury, 'sanctum')->withHeader('Idempotency-Key', 'hostile')->postJson($this->url($submission))->assertUnprocessable();
        $this->defaultHeaders = [];
        $this->assertSame(0, PaymentResolutionCase::count());

        $first = $this->actingAs($treasury, 'sanctum')->postJson($this->url($submission))->assertOk()->json('data.resolution.id');
        $snapshot = [
            PaymentTransaction::count(),
            PaymentResolutionCase::count(),
            PaymentSubmissionHistory::count(),
            PaymentResolutionHistory::count(),
            $submission->fresh()->updated_at?->toIso8601String(),
            $this->operationalState($order, $reservation, $inventory, $product, $item),
        ];
        $second = $this->actingAs($treasury, 'sanctum')->postJson($this->url($submission))->assertOk()->json('data.resolution.id');

        $this->assertSame($first, $second);
        $this->assertSame($snapshot, [
            PaymentTransaction::count(),
            PaymentResolutionCase::count(),
            PaymentSubmissionHistory::count(),
            PaymentResolutionHistory::count(),
            $submission->fresh()->updated_at?->toIso8601String(),
            $this->operationalState($order, $reservation, $inventory, $product, $item),
        ]);
    }

    public function test_all_client_body_shapes_are_rejected_before_any_resolution_write(): void
    {
        [$treasury, , $submission] = $this->fixture(InventoryReservation::EXPIRED);

        foreach ([
            ['amount' => '12.50'],
            ['currency' => 'USD'],
            ['metadata' => ['reason' => 'hostile']],
            ['request' => ['nested' => ['value' => true]]],
            ['items' => []],
        ] as $body) {
            $this->actingAs($treasury, 'sanctum')->postJson($this->url($submission), $body)
                ->assertUnprocessable()
                ->assertJsonMissingPath('exception')
                ->assertJsonMissingPath('trace');
        }

        $this->assertSame(0, PaymentResolutionCase::count());
        $this->assertSame(0, PaymentTransaction::count());
        $this->assertSame(PaymentSubmission::PENDING_REVIEW, $submission->fresh()->status);
    }

    public function test_each_persistence_write_failure_rolls_back_the_opening_transaction(): void
    {
        foreach ([
            'payment_transactions' => 'BEFORE INSERT ON payment_transactions',
            'payment_resolution_cases' => 'BEFORE INSERT ON payment_resolution_cases',
            'payment_submissions' => 'BEFORE UPDATE ON payment_submissions',
            'payment_submission_histories' => 'BEFORE INSERT ON payment_submission_histories',
            'payment_resolution_histories' => 'BEFORE INSERT ON payment_resolution_histories',
            'after_all_writes' => 'AFTER INSERT ON payment_resolution_histories',
        ] as $point => $timing) {
            $this->assertSqliteWriteFailureRollsBack($point, $timing);
        }
    }

    /** @dataProvider transactionAnomalies */
    public function test_incompatible_received_transaction_is_never_repaired(string $column, mixed $value): void
    {
        [$treasury, $order, $submission] = $this->openedFixture();
        $transaction = PaymentTransaction::where('payment_submission_id', $submission->id)->firstOrFail();
        if ($column === 'confirmed_by') {
            $value = User::factory()->create(['role' => 'treasury', 'is_active' => true])->id;
        }
        DB::table('payment_transactions')->where('id', $transaction->id)->update([$column => $value]);
        $before = $this->resolutionSnapshot($order, $submission);

        $this->assertSafeResolutionConflict($this->actingAs($treasury, 'sanctum')->postJson($this->url($submission)));
        $this->assertSame($before, $this->resolutionSnapshot($order, $submission));
    }

    public function transactionAnomalies(): array
    {
        return [
            'amount' => ['amount', '12.49'],
            'currency' => ['currency', 'USD'],
            'payment_method' => ['payment_method', 'tarjeta'],
            'transaction_type' => ['transaction_type', PaymentTransaction::REFUND],
            'status' => ['status', PaymentTransaction::PENDING],
            'channel' => ['collection_method', 'plin'],
            'idempotency key' => ['idempotency_key', 'other-key'],
            'approved scope' => ['approved_scope_key', 'unexpected-scope'],
            'confirmed at' => ['confirmed_at', null],
            'confirmed by' => ['confirmed_by', null],
        ];
    }

    /** @dataProvider caseAnomalies */
    public function test_incompatible_resolution_case_is_never_repaired(string $column, mixed $value): void
    {
        [$treasury, $order, $submission] = $this->openedFixture();
        $case = PaymentResolutionCase::where('payment_submission_id', $submission->id)->firstOrFail();
        if ($column === 'requested_by') {
            $value = User::factory()->create(['role' => 'treasury', 'is_active' => true])->id;
        }
        if ($column === 'received_payment_transaction_id') {
            $other = PaymentTransaction::create([
                'order_id' => $order->id,
                'payment_method' => 'transferencia', 'transaction_type' => PaymentTransaction::PAYMENT,
                'status' => PaymentTransaction::PENDING, 'amount' => '12.50', 'currency' => 'PEN',
                'idempotency_key' => 'test-other-transaction-'.uniqid(),
            ]);
            $value = $other->id;
        }
        DB::table('payment_resolution_cases')->where('id', $case->id)->update([$column => $value]);
        $before = $this->resolutionSnapshot($order, $submission);

        $this->assertSafeResolutionConflict($this->actingAs($treasury, 'sanctum')->postJson($this->url($submission)));
        $this->assertSame($before, $this->resolutionSnapshot($order, $submission));
    }

    public function caseAnomalies(): array
    {
        return [
            'missing received transaction' => ['received_payment_transaction_id', null],
            'amount' => ['amount', '12.49'],
            'currency' => ['currency', 'USD'],
            'status' => ['status', PaymentResolutionCase::REASSIGNED],
            'type' => ['type', PaymentResolutionCase::REFUND],
            'idempotency key' => ['idempotency_key', 'other-case-key'],
            'funds received at' => ['funds_received_at', null],
            'requested at' => ['requested_at', '2037-12-31 00:00:00'],
            'requested by' => ['requested_by', null],
        ];
    }

    /** @dataProvider submissionAnomalies */
    public function test_incompatible_submission_or_order_is_never_repaired(string $table, string $column, mixed $value): void
    {
        [$treasury, $order, $submission] = $this->openedFixture();
        DB::table($table)->where('id', $table === 'orders' ? $order->id : $submission->id)->update([$column => $value]);
        $before = $this->resolutionSnapshot($order, $submission);

        $this->assertSafeResolutionConflict($this->actingAs($treasury, 'sanctum')->postJson($this->url($submission)));
        $this->assertSame($before, $this->resolutionSnapshot($order, $submission));
    }

    public function submissionAnomalies(): array
    {
        return [
            'submission status' => ['payment_submissions', 'status', PaymentSubmission::OBSERVED],
            'reviewed at' => ['payment_submissions', 'reviewed_at', null],
            'decision reason' => ['payment_submissions', 'decision_reason', 'other-reason'],
            'expected amount' => ['payment_submissions', 'expected_amount', '12.49'],
            'submission currency' => ['payment_submissions', 'currency', 'USD'],
            'order method' => ['orders', 'payment_method', 'tarjeta'],
            'approved payment' => ['orders', 'payment_status', 'approved'],
            'rejected payment' => ['orders', 'payment_status', 'rejected'],
            'refund pending payment' => ['orders', 'payment_status', 'refund_pending'],
            'refunded payment' => ['orders', 'payment_status', 'refunded'],
            'paid at' => ['orders', 'paid_at', '2037-12-31 00:00:00'],
            'terminal order' => ['orders', 'status', 'delivered'],
        ];
    }

    /** @dataProvider submissionHistoryAnomalies */
    public function test_incompatible_resolution_opened_submission_history_is_never_repaired(string $column, mixed $value): void
    {
        [$treasury, $order, $submission] = $this->openedFixture();
        $history = PaymentSubmissionHistory::where('payment_submission_id', $submission->id)->where('event', 'resolution_opened')->firstOrFail();
        if ($column === 'actor_id') {
            $value = User::factory()->create(['role' => 'treasury', 'is_active' => true])->id;
        }
        DB::table('payment_submission_histories')->where('id', $history->id)->update([$column => $value]);
        $before = $this->resolutionSnapshot($order, $submission);
        $this->assertSafeResolutionConflict($this->actingAs($treasury, 'sanctum')->postJson($this->url($submission)));
        $this->assertSame($before, $this->resolutionSnapshot($order, $submission));
    }

    public function submissionHistoryAnomalies(): array
    {
        return [
            'from status' => ['from_status', PaymentSubmission::APPROVED],
            'to status' => ['to_status', PaymentSubmission::PENDING_REVIEW],
            'actor' => ['actor_id', null],
            'occurred at' => ['occurred_at', '2037-12-31 00:00:00'],
            'reason' => ['reason', 'other-reason'],
            'metadata null' => ['safe_metadata', null],
            'metadata value' => ['safe_metadata', json_encode(['channel' => 'plin', 'expected_amount' => '12.50', 'currency' => 'PEN'])],
        ];
    }

    /** @dataProvider resolutionHistoryAnomalies */
    public function test_incompatible_opened_resolution_history_is_never_repaired(string $column, mixed $value): void
    {
        [$treasury, $order, $submission] = $this->openedFixture();
        $case = PaymentResolutionCase::where('payment_submission_id', $submission->id)->firstOrFail();
        $history = PaymentResolutionHistory::where('payment_resolution_case_id', $case->id)->where('event', 'opened')->firstOrFail();
        if ($column === 'actor_id') {
            $value = User::factory()->create(['role' => 'treasury', 'is_active' => true])->id;
        }
        DB::table('payment_resolution_histories')->where('id', $history->id)->update([$column => $value]);
        $before = $this->resolutionSnapshot($order, $submission);
        $this->assertSafeResolutionConflict($this->actingAs($treasury, 'sanctum')->postJson($this->url($submission)));
        $this->assertSame($before, $this->resolutionSnapshot($order, $submission));
    }

    public function resolutionHistoryAnomalies(): array
    {
        return [
            'from status' => ['from_status', PaymentResolutionCase::OPEN],
            'to status' => ['to_status', PaymentResolutionCase::REASSIGNED],
            'actor' => ['actor_id', null],
            'occurred at' => ['occurred_at', '2037-12-31 00:00:00'],
            'reason' => ['reason', 'other-reason'],
            'metadata null' => ['safe_metadata', null],
            'metadata value' => ['safe_metadata', json_encode(['currency' => 'PEN', 'amount' => '12.49'])],
        ];
    }

    public function test_deleted_treasury_actor_keeps_a_historic_resolution_idempotent(): void
    {
        [$treasury, $order, $submission] = $this->openedFixture();
        $treasury->delete();
        $before = $this->resolutionSnapshot($order, $submission);
        $this->assertNull(PaymentTransaction::firstOrFail()->confirmed_by);
        $this->assertNull(PaymentResolutionCase::firstOrFail()->requested_by);
        $this->assertNull($submission->fresh()->reviewed_by);

        $case = app(\App\Services\TreasuryPaymentResolutionService::class)->open($submission->id, 999999);
        $this->assertSame(PaymentResolutionCase::firstOrFail()->id, $case->id);
        $this->assertSame($before, $this->resolutionSnapshot($order, $submission));
    }

    /** @dataProvider removableHistoryTables */
    public function test_missing_opening_history_is_a_conflict_and_is_not_recreated(string $table, string $event): void
    {
        [$treasury, $order, $submission] = $this->openedFixture();
        $case = PaymentResolutionCase::where('payment_submission_id', $submission->id)->firstOrFail();
        $key = $table === 'payment_submission_histories' ? 'payment_submission_id' : 'payment_resolution_case_id';
        $owner = $table === 'payment_submission_histories' ? $submission->id : $case->id;
        DB::table($table)->where($key, $owner)->where('event', $event)->delete();
        $before = $this->resolutionSnapshot($order, $submission);

        $this->assertSafeResolutionConflict($this->actingAs($treasury, 'sanctum')->postJson($this->url($submission)));
        $this->assertSame($before, $this->resolutionSnapshot($order, $submission));
    }

    public function removableHistoryTables(): array
    {
        return [
            'submission history' => ['payment_submission_histories', 'resolution_opened'],
            'resolution history' => ['payment_resolution_histories', 'opened'],
        ];
    }

    public function test_received_transaction_without_case_is_a_conflict_and_is_not_repaired(): void
    {
        [$treasury, $order, $submission] = $this->openedFixture();
        $case = PaymentResolutionCase::where('payment_submission_id', $submission->id)->firstOrFail();
        DB::table('payment_resolution_histories')->where('payment_resolution_case_id', $case->id)->delete();
        DB::table('payment_resolution_cases')->where('id', $case->id)->delete();
        $before = $this->resolutionSnapshot($order, $submission);

        $this->assertSafeResolutionConflict($this->actingAs($treasury, 'sanctum')->postJson($this->url($submission)));
        $this->assertSame($before, $this->resolutionSnapshot($order, $submission));
    }

    public function test_case_without_received_transaction_is_a_conflict_after_restrict_fk_is_demonstrated(): void
    {
        [$treasury, $order, $submission] = $this->openedFixture();
        $case = PaymentResolutionCase::where('payment_submission_id', $submission->id)->firstOrFail();
        $transaction = PaymentTransaction::where('payment_submission_id', $submission->id)->firstOrFail();
        $beforeDelete = PaymentTransaction::count();

        try {
            DB::table('payment_transactions')->where('id', $transaction->id)->delete();
            $this->fail('The received transaction FK must restrict deletion while the case references it.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('foreign key', strtolower($exception->getMessage()));
        }
        $this->assertSame($beforeDelete, PaymentTransaction::count());

        DB::table('payment_resolution_cases')->where('id', $case->id)->update(['received_payment_transaction_id' => null]);
        DB::table('payment_transactions')->where('id', $transaction->id)->delete();
        $before = $this->resolutionSnapshot($order, $submission);

        $this->assertSafeResolutionConflict($this->actingAs($treasury, 'sanctum')->postJson($this->url($submission)));
        $this->assertSame($before, $this->resolutionSnapshot($order, $submission));
    }

    /** @dataProvider crossLinkAnomalies */
    public function test_crossed_financial_link_is_a_conflict_without_repair(string $link): void
    {
        [$treasury, $order, $submission] = $this->openedFixture();
        [, $otherOrder, $otherSubmission] = $this->fixture(InventoryReservation::EXPIRED);
        $transaction = PaymentTransaction::where('payment_submission_id', $submission->id)->firstOrFail();
        $case = PaymentResolutionCase::where('payment_submission_id', $submission->id)->firstOrFail();

        if ($link === 'transaction.order_id') {
            DB::table('payment_transactions')->where('id', $transaction->id)->update(['order_id' => $otherOrder->id]);
        } elseif ($link === 'transaction.payment_submission_id') {
            DB::table('payment_transactions')->where('id', $transaction->id)->update(['payment_submission_id' => $otherSubmission->id]);
        } elseif ($link === 'case.payment_submission_id') {
            DB::table('payment_resolution_cases')->where('id', $case->id)->update(['payment_submission_id' => $otherSubmission->id]);
        } else {
            $other = PaymentTransaction::create([
                'order_id' => $otherOrder->id, 'payment_method' => 'transferencia', 'transaction_type' => PaymentTransaction::PAYMENT,
                'status' => PaymentTransaction::RECEIVED, 'amount' => '12.50', 'currency' => 'PEN',
                'idempotency_key' => 'crossed-transaction-'.uniqid(),
            ]);
            DB::table('payment_resolution_cases')->where('id', $case->id)->update(['received_payment_transaction_id' => $other->id]);
        }
        $before = $this->resolutionSnapshot($order, $submission);
        $this->assertSafeResolutionConflict($this->actingAs($treasury, 'sanctum')->postJson($this->url($submission)));
        $this->assertSame($before, $this->resolutionSnapshot($order, $submission));
    }

    public function crossLinkAnomalies(): array
    {
        return [
            'transaction order' => ['transaction.order_id'],
            'transaction submission' => ['transaction.payment_submission_id'],
            'case submission' => ['case.payment_submission_id'],
            'case transaction' => ['case.received_payment_transaction_id'],
        ];
    }

    public function test_duplicate_resolution_opened_submission_history_is_a_conflict_without_consolidation(): void
    {
        [$treasury, $order, $submission] = $this->openedFixture();
        $history = PaymentSubmissionHistory::where('payment_submission_id', $submission->id)->where('event', 'resolution_opened')->firstOrFail();
        $duplicate = $history->getAttributes();
        unset($duplicate['id']);
        DB::table('payment_submission_histories')->insert($duplicate);
        $before = $this->resolutionSnapshot($order, $submission);
        $this->assertSame(2, PaymentSubmissionHistory::where('payment_submission_id', $submission->id)->where('event', 'resolution_opened')->count());

        $this->assertSafeResolutionConflict($this->actingAs($treasury, 'sanctum')->postJson($this->url($submission)));
        $this->assertSame($before, $this->resolutionSnapshot($order, $submission));
    }

    public function test_duplicate_opened_resolution_history_is_a_conflict_without_consolidation(): void
    {
        [$treasury, $order, $submission] = $this->openedFixture();
        $case = PaymentResolutionCase::where('payment_submission_id', $submission->id)->firstOrFail();
        $history = PaymentResolutionHistory::where('payment_resolution_case_id', $case->id)->where('event', 'opened')->firstOrFail();
        $duplicate = $history->getAttributes();
        unset($duplicate['id']);
        DB::table('payment_resolution_histories')->insert($duplicate);
        $before = $this->resolutionSnapshot($order, $submission);
        $this->assertSame(2, PaymentResolutionHistory::where('payment_resolution_case_id', $case->id)->where('event', 'opened')->count());

        $this->assertSafeResolutionConflict($this->actingAs($treasury, 'sanctum')->postJson($this->url($submission)));
        $this->assertSame($before, $this->resolutionSnapshot($order, $submission));
    }

    /** @dataProvider historyNotNullColumns */
    public function test_history_required_columns_are_enforced_by_the_database(string $table, string $ownerColumn, string $event, string $field): void
    {
        [, , $submission] = $this->openedFixture();
        $case = PaymentResolutionCase::where('payment_submission_id', $submission->id)->firstOrFail();
        $owner = $table === 'payment_submission_histories' ? $submission->id : $case->id;
        $source = DB::table($table)->where($ownerColumn, $owner)->where('event', $event)->first();
        $payload = (array) $source;
        unset($payload['id']);
        $payload[$field] = null;
        $count = DB::table($table)->count();

        try {
            DB::transaction(fn () => DB::table($table)->insert($payload));
            $this->fail("{$table}.{$field} must be NOT NULL.");
        } catch (QueryException $exception) {
            $this->assertMatchesRegularExpression('/not null|cannot be null/i', $exception->getMessage());
        }

        $this->assertSame($count, DB::table($table)->count());
        $this->assertNotNull(DB::table($table)->where($ownerColumn, $owner)->first());
    }

    public function historyNotNullColumns(): array
    {
        return [
            'submission event' => ['payment_submission_histories', 'payment_submission_id', 'resolution_opened', 'event'],
            'submission to status' => ['payment_submission_histories', 'payment_submission_id', 'resolution_opened', 'to_status'],
            'submission occurred at' => ['payment_submission_histories', 'payment_submission_id', 'resolution_opened', 'occurred_at'],
            'resolution event' => ['payment_resolution_histories', 'payment_resolution_case_id', 'opened', 'event'],
            'resolution to status' => ['payment_resolution_histories', 'payment_resolution_case_id', 'opened', 'to_status'],
            'resolution occurred at' => ['payment_resolution_histories', 'payment_resolution_case_id', 'opened', 'occurred_at'],
        ];
    }

    /** @dataProvider incompatibleSubmissionStatuses */
    public function test_incompatible_submission_statuses_cannot_open_a_resolution(string $status): void
    {
        [$treasury, , $submission] = $this->fixture(InventoryReservation::EXPIRED, $status);

        $this->actingAs($treasury, 'sanctum')->postJson($this->url($submission))
            ->assertConflict()
            ->assertJsonPath('code', 'payment_resolution_conflict');
        $this->assertSame(0, PaymentResolutionCase::count());
    }

    public function incompatibleSubmissionStatuses(): array
    {
        return [
            'rejected' => [PaymentSubmission::REJECTED],
            'approved' => [PaymentSubmission::APPROVED],
            'expired' => [PaymentSubmission::EXPIRED],
            'canceled' => [PaymentSubmission::CANCELED],
            'refund_pending' => [PaymentSubmission::REFUND_PENDING],
            'refunded' => [PaymentSubmission::REFUNDED],
        ];
    }

    public function test_detail_allows_opening_for_a_canceled_order_with_an_expired_reservation(): void
    {
        [$treasury, $order, $submission] = $this->fixture(InventoryReservation::EXPIRED);
        $order->update(['status' => 'canceled']);

        $this->actingAs($treasury, 'sanctum')
            ->getJson('/api/v1/treasury/payment-submissions/'.$submission->id)
            ->assertOk()
            ->assertJsonPath('data.can_open_resolution', true);
    }

    public function test_detail_disallows_opening_for_a_canceled_order_with_only_a_manually_released_reservation(): void
    {
        [$treasury, $order, $submission] = $this->fixture(InventoryReservation::RELEASED);
        $order->update(['status' => 'canceled']);

        $this->actingAs($treasury, 'sanctum')
            ->getJson('/api/v1/treasury/payment-submissions/'.$submission->id)
            ->assertOk()
            ->assertJsonPath('data.can_open_resolution', false);
    }

    public function test_detail_disallows_opening_while_the_active_reservation_is_still_usable(): void
    {
        [$treasury, , $submission] = $this->fixture(InventoryReservation::ACTIVE, PaymentSubmission::PENDING_REVIEW, true);

        $this->actingAs($treasury, 'sanctum')
            ->getJson('/api/v1/treasury/payment-submissions/'.$submission->id)
            ->assertOk()
            ->assertJsonPath('data.can_open_resolution', false)
            ->assertJsonPath('data.reservation_status', InventoryReservation::ACTIVE);
    }

    public function test_detail_allows_opening_for_an_active_but_expired_reservation(): void
    {
        [$treasury, , $submission] = $this->fixture(InventoryReservation::ACTIVE);

        $this->actingAs($treasury, 'sanctum')
            ->getJson('/api/v1/treasury/payment-submissions/'.$submission->id)
            ->assertOk()
            ->assertJsonPath('data.can_open_resolution', true)
            ->assertJsonPath('data.reservation_status', InventoryReservation::EXPIRED);
    }

    public function test_detail_disallows_opening_for_a_consumed_reservation(): void
    {
        [$treasury, , $submission] = $this->fixture(InventoryReservation::CONSUMED);

        $this->actingAs($treasury, 'sanctum')
            ->getJson('/api/v1/treasury/payment-submissions/'.$submission->id)
            ->assertOk()
            ->assertJsonPath('data.can_open_resolution', false);
    }

    public function test_detail_disallows_opening_after_the_transaction_and_case_already_exist(): void
    {
        [$treasury, , $submission] = $this->fixture(InventoryReservation::EXPIRED);
        app(TreasuryPaymentResolutionService::class)->open($submission->id, $treasury->id);

        $response = $this->actingAs($treasury, 'sanctum')
            ->getJson('/api/v1/treasury/payment-submissions/'.$submission->id)
            ->assertOk()
            ->assertJsonPath('data.can_open_resolution', false)
            ->assertJsonPath('data.status', PaymentSubmission::MANUAL_RESOLUTION_REQUIRED)
            ->assertJsonPath('data.resolution.status', PaymentResolutionCase::OPEN);

        foreach (['duplicate_fingerprint', 'idempotency_key', 'approved_scope_key', 'metadata', 'reservation_sequence', 'resolution_case_id'] as $sensitive) {
            $this->assertStringNotContainsString($sensitive, $response->getContent());
        }
    }

    private function fixture(string $reservationStatus, string $submissionStatus = PaymentSubmission::PENDING_REVIEW, bool $validReservation = false): array
    {
        $treasury = User::factory()->create(['role' => 'treasury', 'is_active' => true]);
        $customer = User::factory()->create(['role' => 'customer', 'is_active' => true]);
        $branch = Branch::create(['code' => 'BR-'.uniqid(), 'name' => 'Sede', 'address' => 'Dirección', 'is_active' => true]);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'code' => 'WH-'.uniqid(), 'name' => 'Almacén', 'is_active' => true]);
        $product = Product::create(['name' => 'Producto '.uniqid(), 'slug' => 'producto-'.uniqid(), 'price' => '12.50', 'stock' => 10]);
        $inventory = WarehouseInventory::create(['warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'quantity' => 10, 'reserved_quantity' => 1]);
        $order = Order::create(['user_id' => $customer->id, 'status' => 'pending', 'total' => '12.50', 'shipping_info' => ['recipient_name' => 'Cliente'], 'payment_method' => 'transferencia', 'payment_status' => 'pending', 'reserved_until' => $validReservation ? now()->addHour() : now()->subMinute(), 'delivery_type' => 'delivery', 'tracking_status' => 'pending', 'fulfillment_status' => 'reserved']);
        $item = OrderItem::create(['order_id' => $order->id, 'product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'quantity' => 1, 'price' => '12.50', 'subtotal' => '12.50']);
        $reservation = InventoryReservation::create(['order_id' => $order->id, 'order_item_id' => $item->id, 'product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'quantity' => 1, 'status' => $reservationStatus, 'expires_at' => $validReservation ? now()->addHour() : now()->subMinute(), 'released_at' => $reservationStatus === InventoryReservation::RELEASED ? now()->subMinute() : null, 'idempotency_key' => 'reservation-'.uniqid()]);
        $submission = PaymentSubmission::create(['order_id' => $order->id, 'channel' => 'yape', 'status' => $submissionStatus, 'expected_amount' => '12.50', 'currency' => 'PEN', 'operation_number' => '00001234', 'normalized_operation_number' => '00001234', 'duplicate_fingerprint' => hash('sha256', uniqid('', true)), 'declared_paid_at' => now()->subMinute(), 'origin_phone_last4' => '1234', 'receiving_account_snapshot' => [], 'submitted_by' => $customer->id, 'submitted_at' => now()->subMinute(), 'idempotency_key' => 'submission-'.uniqid()]);

        return [$treasury, $order, $submission, $reservation, $inventory, $product, $item];
    }

    private function operationalState(Order $order, InventoryReservation $reservation, WarehouseInventory $inventory, Product $product, OrderItem $item): array
    {
        return [
            array_merge($order->fresh()->only(['status', 'fulfillment_status', 'payment_status']), ['reserved_until' => $order->fresh()->reserved_until?->toIso8601String()]),
            $order->fresh()->paid_at?->toIso8601String(),
            array_merge($reservation->fresh()->only(['status', 'quantity', 'reservation_sequence']), [
                'expires_at' => $reservation->fresh()->expires_at?->toIso8601String(),
                'consumed_at' => $reservation->fresh()->consumed_at?->toIso8601String(),
                'released_at' => $reservation->fresh()->released_at?->toIso8601String(),
            ]),
            $inventory->fresh()->only(['quantity', 'reserved_quantity']),
            $product->fresh()->stock,
            $item->fresh()->quantity,
            InventoryMovement::count(),
        ];
    }

    private function assertSqliteWriteFailureRollsBack(string $point, string $timing): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('SQLite-specific RAISE(ABORT) trigger; transactional rollback is certified separately on MySQL.');
        }

        [$treasury, $order, $submission, $reservation, $inventory, $product, $item] = $this->fixture(InventoryReservation::EXPIRED);
        $before = [
            'operational' => $this->operationalState($order, $reservation, $inventory, $product, $item),
            'submission' => $this->submissionState($submission),
            'transactions' => PaymentTransaction::count(),
            'cases' => PaymentResolutionCase::count(),
            'submission_history' => PaymentSubmissionHistory::count(),
            'resolution_history' => PaymentResolutionHistory::count(),
        ];
        $trigger = 'resolution_opening_'.$point;
        DB::unprepared("CREATE TRIGGER {$trigger} {$timing} BEGIN SELECT RAISE(ABORT, 'forced {$point} failure'); END");

        try {
            app(TreasuryPaymentResolutionService::class)->open($submission->id, $treasury->id);
            $this->fail("The {$point} trigger must abort the resolution opening.");
        } catch (QueryException) {
            // The production service intentionally rethrows non-UNIQUE database failures.
        } finally {
            DB::unprepared("DROP TRIGGER IF EXISTS {$trigger}");
        }

        $this->assertSame($before, [
            'operational' => $this->operationalState($order, $reservation, $inventory, $product, $item),
            'submission' => $this->submissionState($submission),
            'transactions' => PaymentTransaction::count(),
            'cases' => PaymentResolutionCase::count(),
            'submission_history' => PaymentSubmissionHistory::count(),
            'resolution_history' => PaymentResolutionHistory::count(),
        ]);
    }

    private function url(PaymentSubmission $submission): string
    {
        return '/api/v1/treasury/payment-submissions/'.$submission->id.'/resolution';
    }

    private function submissionState(PaymentSubmission $submission): array
    {
        $fresh = $submission->fresh();

        return [
            'status' => $fresh->status,
            'reviewed_by' => $fresh->reviewed_by,
            'reviewed_at' => $fresh->reviewed_at?->toIso8601String(),
            'decision_reason' => $fresh->decision_reason,
            'updated_at' => $fresh->updated_at?->toIso8601String(),
        ];
    }

    private function openedFixture(): array
    {
        [$treasury, $order, $submission] = $this->fixture(InventoryReservation::EXPIRED);
        $this->actingAs($treasury, 'sanctum')->postJson($this->url($submission))->assertOk();

        return [$treasury, $order, $submission];
    }

    private function resolutionSnapshot(Order $order, PaymentSubmission $submission): array
    {
        return [
            'transaction' => PaymentTransaction::query()->orderBy('id')->get()->map(fn ($record) => $record->getAttributes())->all(),
            'case' => PaymentResolutionCase::query()->orderBy('id')->get()->map(fn ($record) => $record->getAttributes())->all(),
            'submission' => $this->submissionState($submission),
            'submission_history' => PaymentSubmissionHistory::query()->orderBy('id')->get()->map(fn ($record) => $record->getAttributes())->all(),
            'resolution_history' => PaymentResolutionHistory::query()->orderBy('id')->get()->map(fn ($record) => $record->getAttributes())->all(),
            'order' => $order->fresh()->getAttributes(),
            'reservations' => InventoryReservation::where('order_id', $order->id)->orderBy('id')->get()->map(fn ($record) => $record->getAttributes())->all(),
            'inventory' => WarehouseInventory::query()->orderBy('id')->get()->map(fn ($record) => $record->getAttributes())->all(),
            'movements' => InventoryMovement::query()->orderBy('id')->get()->map(fn ($record) => $record->getAttributes())->all(),
        ];
    }

    private function assertSafeResolutionConflict($response): void
    {
        $response->assertConflict()->assertJsonPath('code', 'payment_resolution_conflict')
            ->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Pragma', 'no-cache');
        foreach (['operation_number', 'fingerprint', 'idempotency_key', 'approved_scope_key', 'receiving_account_snapshot', 'constraint', 'sql', 'bindings', 'exception', 'file', 'line', 'trace'] as $key) {
            $response->assertJsonMissingPath($key);
        }
    }
}
