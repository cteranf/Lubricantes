<?php

namespace Tests\Feature;

use App\Exceptions\InventoryReservationConflictException;
use App\Http\Resources\TreasuryPaymentSubmissionDetailResource;
use App\Models\Branch;
use App\Models\InventoryMovement;
use App\Models\InventoryReservation;
use App\Models\Order;
use App\Models\OrderFulfillmentHistory;
use App\Models\OrderItem;
use App\Models\PaymentRefund;
use App\Models\PaymentResolutionCase;
use App\Models\PaymentResolutionHistory;
use App\Models\PaymentSubmission;
use App\Models\PaymentSubmissionHistory;
use App\Models\PaymentTransaction;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseInventory;
use App\Services\InventoryReservationExpirationService;
use App\Services\InventoryService;
use App\Services\TreasuryPaymentReassignmentService;
use App\Services\TreasuryPaymentResolutionService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use PDOException;
use Tests\TestCase;

class TreasuryPaymentReassignmentHttpTest extends TestCase
{
    use RefreshDatabase;

    public function test_sensitive_snapshot_canonicalization_ignores_object_key_order_but_preserves_types_and_list_order(): void
    {
        $first = ['object' => ['beta' => 2, 'alpha' => 1], 'list' => [1, 2]];
        $same = ['list' => [1, 2], 'object' => ['alpha' => 1, 'beta' => 2]];

        $this->assertTrue(hash_equals($this->sensitiveDigest($first), $this->sensitiveDigest($same)));
        $this->assertFalse(hash_equals($this->sensitiveDigest($first), $this->sensitiveDigest(['object' => ['alpha' => 1, 'beta' => '2'], 'list' => [1, 2]])));
        $this->assertFalse(hash_equals($this->sensitiveDigest($first), $this->sensitiveDigest(['object' => ['alpha' => 1, 'beta' => 2], 'list' => [2, 1]])));
    }

    public function test_authorization_matrix_rejects_anonymous_and_non_treasury_actors(): void
    {
        $fixture = $this->fixture();
        $customer = User::factory()->create(['role' => 'customer', 'is_active' => true]);
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $inactiveTreasury = User::factory()->create(['role' => 'treasury', 'is_active' => false]);

        $this->postJson($this->url($fixture['submission']))
            ->assertUnauthorized()
            ->assertJsonMissingPath('exception');
        $this->actingAs($customer, 'sanctum')->postJson($this->url($fixture['submission']))
            ->assertForbidden()
            ->assertJsonMissingPath('exception');
        $this->actingAs($admin, 'sanctum')->postJson($this->url($fixture['submission']))
            ->assertForbidden()
            ->assertJsonMissingPath('exception');
        $this->actingAs($inactiveTreasury, 'sanctum')->postJson($this->url($fixture['submission']))
            ->assertForbidden()
            ->assertJsonPath('code', 'account_inactive')
            ->assertJsonMissingPath('exception');

        $this->assertSame(PaymentSubmission::MANUAL_RESOLUTION_REQUIRED, $fixture['submission']->fresh()->status);
        $this->assertSame(PaymentTransaction::RECEIVED, $fixture['transaction']->fresh()->status);
        $this->assertSame(PaymentResolutionCase::OPEN, $fixture['case']->fresh()->status);
    }

    public function test_missing_submission_uses_safe_route_model_binding_response(): void
    {
        $treasury = User::factory()->create(['role' => 'treasury', 'is_active' => true]);

        $this->actingAs($treasury, 'sanctum')
            ->postJson('/api/v1/treasury/payment-submissions/999999/resolution/reassign')
            ->assertNotFound()
            ->assertJsonPath('code', 'payment_submission_not_found')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Pragma', 'no-cache')
            ->assertJsonMissingPath('exception')
            ->assertJsonMissingPath('trace');
    }

    public function test_submission_outside_the_resolution_flow_is_a_safe_conflict(): void
    {
        $fixture = $this->fixture(openResolution: false);

        $response = $this->actingAs($fixture['treasury'], 'sanctum')
            ->postJson($this->url($fixture['submission']))
            ->assertConflict()
            ->assertJsonPath('code', 'payment_reassignment_conflict');

        $this->assertPrivateSafeResponse($response);
        $this->assertSame(PaymentSubmission::PENDING_REVIEW, $fixture['submission']->fresh()->status);
        $this->assertSame(0, PaymentTransaction::where('payment_submission_id', $fixture['submission']->id)->count());
        $this->assertSame(0, PaymentResolutionCase::where('payment_submission_id', $fixture['submission']->id)->count());
    }

    public function test_exactly_empty_body_is_allowed_and_returns_an_allowlisted_private_resource(): void
    {
        $fixture = $this->fixture();

        $response = $this->actingAs($fixture['treasury'], 'sanctum')
            ->call('POST', $this->url($fixture['submission']), [], [], [], [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
            ], '{}');

        $case = $fixture['case']->fresh();
        $response->assertOk()->assertExactJson([
            'data' => [
                'message' => 'El pago fue aprobado y el stock fue reasignado.',
                'resolution' => [
                    'id' => $case->id,
                    'payment_submission_id' => $fixture['submission']->id,
                    'order_id' => $fixture['order']->id,
                    'type' => PaymentResolutionCase::STOCK_REASSIGNMENT,
                    'status' => PaymentResolutionCase::REASSIGNED,
                    'amount' => '25.00',
                    'currency' => 'PEN',
                    'resolved_at' => $case->resolved_at?->toIso8601String(),
                ],
            ],
        ]);
        $this->assertPrivateSafeResponse($response);
    }

    public function test_treasury_detail_exposes_only_safe_reassignment_availability_and_final_state(): void
    {
        $fixture = $this->fixture();
        $detailUrl = '/api/v1/treasury/payment-submissions/'.$fixture['submission']->id;

        $this->actingAs($fixture['treasury'], 'sanctum')->getJson($detailUrl)
            ->assertOk()
            ->assertJsonPath('data.status', PaymentSubmission::MANUAL_RESOLUTION_REQUIRED)
            ->assertJsonPath('data.resolution.status', PaymentResolutionCase::OPEN)
            ->assertJsonPath('data.resolution.type', null)
            ->assertJsonPath('data.can_reassign_and_approve', true)
            ->assertJsonMissingPath('data.resolution.idempotency_key')
            ->assertJsonMissingPath('data.resolution.metadata')
            ->assertJsonMissingPath('data.resolution.received_payment_transaction_id')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Pragma', 'no-cache');

        $this->postJson($this->url($fixture['submission']))->assertOk();

        $this->getJson($detailUrl)
            ->assertOk()
            ->assertJsonPath('data.status', PaymentSubmission::APPROVED)
            ->assertJsonPath('data.resolution.status', PaymentResolutionCase::REASSIGNED)
            ->assertJsonPath('data.resolution.type', PaymentResolutionCase::STOCK_REASSIGNMENT)
            ->assertJsonPath('data.can_reassign_and_approve', false)
            ->assertJsonPath('data.approved_payment.status', PaymentTransaction::APPROVED)
            ->assertJsonMissingPath('data.resolution.idempotency_key')
            ->assertJsonMissingPath('data.resolution.metadata')
            ->assertJsonMissingPath('data.resolution.received_payment_transaction_id')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Pragma', 'no-cache');
    }

    /**
     * @dataProvider incoherentAvailabilityProvider
     */
    public function test_treasury_detail_hides_reassignment_for_each_incoherent_b1_state_without_writes(string $anomaly): void
    {
        $fixture = $this->fixture();
        $this->applyAvailabilityAnomaly($fixture, $anomaly);
        $before = $this->snapshot($fixture);

        $response = $this->actingAs($fixture['treasury'], 'sanctum')
            ->getJson('/api/v1/treasury/payment-submissions/'.$fixture['submission']->id)
            ->assertOk()
            ->assertJsonPath('data.can_reassign_and_approve', false)
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Pragma', 'no-cache');

        $this->assertAvailabilityResponseDoesNotExposeInternalData($response->getContent());
        $this->assertSnapshotUnchanged($before, $this->snapshot($fixture));
    }

    /** @dataProvider nonCanceledReservationEvidenceProvider */
    public function test_non_canceled_orders_require_the_same_complete_reservation_evidence_in_get_and_post(string $anomaly): void
    {
        $fixture = $this->fixture();
        $this->applyNonCanceledReservationAnomaly($fixture, $anomaly);
        $before = $this->snapshot($fixture);

        $detail = $this->actingAs($fixture['treasury'], 'sanctum')
            ->getJson('/api/v1/treasury/payment-submissions/'.$fixture['submission']->id)
            ->assertOk()
            ->assertJsonPath('data.can_reassign_and_approve', false);
        $this->assertAvailabilityResponseDoesNotExposeInternalData($detail->getContent());

        $response = $this->actingAs($fixture['treasury'], 'sanctum')
            ->postJson($this->url($fixture['submission']), [])
            ->assertConflict()
            ->assertJsonPath('code', 'payment_reassignment_conflict');

        $this->assertPrivateSafeResponse($response);
        $this->assertSnapshotUnchanged($before, $this->snapshot($fixture));
    }

    public static function nonCanceledReservationEvidenceProvider(): array
    {
        return [
            'item without original reservation' => ['missing_original'],
            'reservation belongs to another order' => ['cross_order'],
            'reservation belongs to another item' => ['cross_item'],
            'reservation quantity differs from item' => ['quantity'],
            'historical reservation status is incompatible' => ['status'],
            'one item has no reservation' => ['incomplete_set'],
            'crossed extra reservation evidence' => ['crossed_evidence'],
        ];
    }

    public static function incoherentAvailabilityProvider(): array
    {
        return [
            'submission status' => ['submission_status'],
            'submission reviewed at' => ['submission_reviewed_at'],
            'submission review reason' => ['submission_review'],
            'submission amount' => ['submission_amount'],
            'submission currency' => ['submission_currency'],
            'submission channel' => ['submission_channel'],
            'order paid' => ['order_paid'],
            'order paid timestamp' => ['order_paid_at'],
            'order terminal' => ['order_terminal'],
            'transaction absent' => ['transaction_absent'],
            'transaction status' => ['transaction_status'],
            'transaction amount' => ['transaction_amount'],
            'transaction currency' => ['transaction_currency'],
            'transaction payment method' => ['transaction_method'],
            'transaction type' => ['transaction_type'],
            'transaction channel' => ['transaction_channel'],
            'transaction key' => ['transaction_key'],
            'transaction approved scope' => ['transaction_scope'],
            'transaction confirmed at' => ['transaction_confirmed_at'],
            'transaction actor' => ['transaction_actor'],
            'transaction cross order' => ['transaction_cross_order'],
            'transaction cross submission' => ['transaction_cross_submission'],
            'case absent' => ['case_absent'],
            'case status' => ['case_status'],
            'case type' => ['case_type'],
            'case amount' => ['case_amount'],
            'case currency' => ['case_currency'],
            'case key' => ['case_key'],
            'case received timestamp' => ['case_funds_received_at'],
            'case requested timestamp' => ['case_requested_at'],
            'case actor' => ['case_actor'],
            'case transaction link' => ['case_transaction_link'],
            'case cross submission' => ['case_cross_submission'],
            'submission opening history absent' => ['submission_history_absent'],
            'submission opening history duplicated' => ['submission_history_duplicate'],
            'resolution opening history absent' => ['resolution_history_absent'],
            'resolution opening history duplicated' => ['resolution_history_duplicate'],
            'opening transition' => ['history_transition'],
            'opening event' => ['history_event'],
            'opening actor' => ['history_actor'],
            'opening timestamp' => ['history_timestamp'],
            'opening metadata' => ['history_metadata'],
            'resolution transition' => ['resolution_history_transition'],
            'resolution actor' => ['resolution_history_actor'],
            'resolution timestamp' => ['resolution_history_timestamp'],
            'resolution metadata' => ['resolution_history_metadata'],
            'refund exists' => ['refund'],
            'manual cancellation without expiration evidence' => ['manual_cancellation'],
        ];
    }

    public function test_post_remains_authoritative_when_state_changes_after_available_detail_was_read(): void
    {
        $fixture = $this->fixture();

        $this->actingAs($fixture['treasury'], 'sanctum')
            ->getJson('/api/v1/treasury/payment-submissions/'.$fixture['submission']->id)
            ->assertOk()
            ->assertJsonPath('data.can_reassign_and_approve', true);

        DB::table('orders')->where('id', $fixture['order']->id)->update(['payment_status' => 'approved']);
        $before = $this->snapshot($fixture);

        $response = $this->actingAs($fixture['treasury'], 'sanctum')
            ->postJson($this->url($fixture['submission']), [])
            ->assertConflict()
            ->assertJsonPath('code', 'payment_reassignment_conflict');

        $this->assertPrivateSafeResponse($response);
        $this->assertSnapshotUnchanged($before, $this->snapshot($fixture));
    }

    public function test_post_revalidates_current_locked_expiration_provenance_after_available_detail_was_read(): void
    {
        $fixture = $this->fixture(expireAutomatically: true);

        $this->actingAs($fixture['treasury'], 'sanctum')
            ->getJson('/api/v1/treasury/payment-submissions/'.$fixture['submission']->id)
            ->assertOk()
            ->assertJsonPath('data.can_reassign_and_approve', true);

        DB::table('inventory_reservations')->where('id', $fixture['original']->id)->update([
            'status' => InventoryReservation::RELEASED,
            'metadata' => json_encode(['release_reason' => 'order_canceled'], JSON_THROW_ON_ERROR),
        ]);
        $before = $this->snapshot($fixture);

        $response = $this->actingAs($fixture['treasury'], 'sanctum')
            ->postJson($this->url($fixture['submission']), [])
            ->assertConflict()
            ->assertJsonPath('code', 'payment_reassignment_conflict');

        $this->assertPrivateSafeResponse($response);
        $this->assertSnapshotUnchanged($before, $this->snapshot($fixture));
    }

    public function test_post_uses_the_current_locked_fulfillment_history_instead_of_the_prior_get_evidence(): void
    {
        $fixture = $this->fixture(expireAutomatically: true);

        $this->actingAs($fixture['treasury'], 'sanctum')
            ->getJson('/api/v1/treasury/payment-submissions/'.$fixture['submission']->id)
            ->assertOk()
            ->assertJsonPath('data.can_reassign_and_approve', true);

        DB::table('order_fulfillment_histories')
            ->where('order_id', $fixture['order']->id)
            ->where('idempotency_key', 'reservation-expired-order-'.$fixture['order']->id)
            ->update(['idempotency_key' => 'noncanonical-expiration-history-'.Str::uuid()]);
        $before = $this->snapshot($fixture);

        $response = $this->actingAs($fixture['treasury'], 'sanctum')
            ->postJson($this->url($fixture['submission']), [])
            ->assertConflict()
            ->assertJsonPath('code', 'payment_reassignment_conflict');

        $this->assertPrivateSafeResponse($response);
        $this->assertSnapshotUnchanged($before, $this->snapshot($fixture));
    }

    public function test_resource_availability_does_not_dirty_or_extend_the_submission_model(): void
    {
        $fixture = $this->fixture();
        $submission = $fixture['submission']->fresh([
            'order.user',
            'order.items.currentReservation',
            'reviewer',
            'histories.actor',
            'paymentTransaction.confirmer',
            'resolutionCase',
        ]);
        $attributes = $submission->getAttributes();

        $resource = (new TreasuryPaymentSubmissionDetailResource($submission))
            ->withAvailability(false, true);
        $payload = $resource->resolve(request());

        $this->assertFalse($submission->isDirty());
        $this->assertSame($attributes, $submission->getAttributes());
        $this->assertArrayNotHasKey('can_open_resolution', $submission->getAttributes());
        $this->assertArrayNotHasKey('can_reassign_and_approve', $submission->getAttributes());
        $this->assertFalse($payload['can_open_resolution']);
        $this->assertTrue($payload['can_reassign_and_approve']);
    }

    public function test_unexpected_query_exception_during_availability_reaches_the_safe_500_handler(): void
    {
        $fixture = $this->fixture();
        $pdo = new PDOException('sensitive availability query detail', 1);
        $pdo->errorInfo = ['42000', 1, 'sensitive availability query detail'];
        $query = new QueryException('sensitive availability sql', [], $pdo);
        $original = $this->app->make(TreasuryPaymentReassignmentService::class);
        $service = new class($this->app->make(InventoryService::class), $query) extends TreasuryPaymentReassignmentService
        {
            public function __construct(InventoryService $inventory, private QueryException $failure)
            {
                parent::__construct($inventory);
            }

            protected function initialFinancialState(PaymentSubmission $submission, Order $order, bool $lock = false): array
            {
                throw $this->failure;
            }
        };
        $this->app->instance(TreasuryPaymentReassignmentService::class, $service);
        $originalDebug = config('app.debug');
        $this->app['config']->set('app.debug', false);

        try {
            $response = $this->actingAs($fixture['treasury'], 'sanctum')
                ->getJson('/api/v1/treasury/payment-submissions/'.$fixture['submission']->id)
                ->assertStatus(500)
                ->assertJsonMissingPath('exception')
                ->assertJsonMissingPath('trace');
        } finally {
            $this->app->instance(TreasuryPaymentReassignmentService::class, $original);
            $this->app['config']->set('app.debug', $originalDebug);
        }

        $this->assertSame($originalDebug, config('app.debug'));
        $this->assertStringNotContainsString('sensitive availability', Str::lower($response->getContent()));
    }

    public function test_database_prevents_duplicate_transaction_and_case_for_the_same_submission(): void
    {
        $fixture = $this->fixture();

        $transactionAttributes = $fixture['transaction']->getAttributes();
        unset($transactionAttributes['id'], $transactionAttributes['created_at'], $transactionAttributes['updated_at']);
        $transactionAttributes['idempotency_key'] = 'availability-duplicate-transaction-'.Str::uuid();
        try {
            PaymentTransaction::forceCreate($transactionAttributes);
            $this->fail('The payment submission transaction UNIQUE constraint was not enforced.');
        } catch (QueryException $exception) {
            $this->assertUniqueConstraintViolation($exception, 'pt_submission_unique', 'payment_submission_id');
            $this->assertSame(1, PaymentTransaction::where('payment_submission_id', $fixture['submission']->id)->count());
        }

        $caseAttributes = $fixture['case']->getAttributes();
        unset($caseAttributes['id'], $caseAttributes['created_at'], $caseAttributes['updated_at']);
        $caseAttributes['received_payment_transaction_id'] = null;
        $caseAttributes['idempotency_key'] = 'availability-duplicate-case-'.Str::uuid();
        try {
            PaymentResolutionCase::forceCreate($caseAttributes);
            $this->fail('The payment submission resolution case UNIQUE constraint was not enforced.');
        } catch (QueryException $exception) {
            $this->assertUniqueConstraintViolation($exception, 'prc_submission_unique', 'payment_submission_id');
            $this->assertSame(1, PaymentResolutionCase::where('payment_submission_id', $fixture['submission']->id)->count());
        }

        $this->actingAs($fixture['treasury'], 'sanctum')
            ->getJson('/api/v1/treasury/payment-submissions/'.$fixture['submission']->id)
            ->assertOk()
            ->assertJsonPath('data.can_reassign_and_approve', true);
    }

    public function test_hostile_fields_and_nested_payloads_are_rejected_before_the_service_writes(): void
    {
        $fixture = $this->fixture();
        $before = $this->snapshot($fixture);

        foreach ([
            ['amount' => '0.01'],
            ['currency' => 'USD'],
            ['operation_number' => 'secret'],
            ['receiving_account_id' => 1],
            ['reservation' => ['id' => 1]],
            ['warehouse_id' => 1],
            ['transaction_id' => 1],
            ['case_id' => 1],
            ['resolved_at' => now()->toIso8601String()],
            ['actor_id' => $fixture['treasury']->id],
            ['nested' => ['payload' => ['amount' => '25.00']]],
        ] as $payload) {
            $this->actingAs($fixture['treasury'], 'sanctum')
                ->postJson($this->url($fixture['submission']), $payload)
                ->assertUnprocessable()
                ->assertJsonValidationErrors('request')
                ->assertJsonMissingPath('exception');
        }

        $this->assertSnapshotUnchanged($before, $this->snapshot($fixture));
    }

    public function test_uploaded_files_are_rejected_before_the_service_writes(): void
    {
        $fixture = $this->fixture();
        $before = $this->snapshot($fixture);

        $this->actingAs($fixture['treasury'], 'sanctum')
            ->call('POST', $this->url($fixture['submission']), [], [], [
                'receipt' => UploadedFile::fake()->create('receipt.txt', 1, 'text/plain'),
            ], ['HTTP_ACCEPT' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('request');

        $this->assertSnapshotUnchanged($before, $this->snapshot($fixture));
    }

    public function test_client_idempotency_key_is_rejected_before_the_service_writes(): void
    {
        $fixture = $this->fixture();
        $before = $this->snapshot($fixture);

        $this->actingAs($fixture['treasury'], 'sanctum')
            ->withHeader('Idempotency-Key', 'client-controlled')
            ->postJson($this->url($fixture['submission']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('idempotency_key');

        $this->assertSnapshotUnchanged($before, $this->snapshot($fixture));
    }

    public function test_success_reassigns_and_approves_through_the_certified_service(): void
    {
        $fixture = $this->fixture();

        $response = $this->actingAs($fixture['treasury'], 'sanctum')
            ->postJson($this->url($fixture['submission']))
            ->assertOk()
            ->assertJsonPath('data.resolution.id', $fixture['case']->id)
            ->assertJsonPath('data.resolution.status', PaymentResolutionCase::REASSIGNED);

        $this->assertPrivateSafeResponse($response);
        $this->assertSame(PaymentTransaction::APPROVED, $fixture['transaction']->fresh()->status);
        $this->assertSame(PaymentSubmission::APPROVED, $fixture['submission']->fresh()->status);
        $this->assertSame('approved', $fixture['order']->fresh()->payment_status);
        $this->assertSame(1, InventoryReservation::where('resolution_case_id', $fixture['case']->id)->where('status', InventoryReservation::CONSUMED)->count());
        $this->assertSame(1, InventoryMovement::where('reference_type', 'order')
            ->where('reference_id', (string) $fixture['order']->id)
            ->where('type', InventoryMovement::SALE)->count());
    }

    public function test_automatic_expiration_evidence_allows_controlled_reopening(): void
    {
        $fixture = $this->fixture(expireAutomatically: true);
        $this->assertSame('canceled', $fixture['order']->fresh()->status);

        $this->actingAs($fixture['treasury'], 'sanctum')
            ->postJson($this->url($fixture['submission']))
            ->assertOk();

        $this->assertSame('pending', $fixture['order']->fresh()->status);
        $this->assertSame(Order::FULFILLMENT_RESERVED, $fixture['order']->fresh()->fulfillment_status);
        $this->assertSame('approved', $fixture['order']->fresh()->payment_status);
        $this->assertSame(1, OrderFulfillmentHistory::where('order_id', $fixture['order']->id)
            ->where('idempotency_key', 'resolution-reopened-order-'.$fixture['order']->id.'-case-'.$fixture['case']->id)->count());
    }

    public function test_manual_cancellation_is_a_private_conflict_without_repair(): void
    {
        $fixture = $this->fixture(originalStatus: InventoryReservation::RELEASED);
        $fixture['order']->update([
            'status' => 'canceled',
            'tracking_status' => 'canceled',
            'fulfillment_status' => Order::FULFILLMENT_CANCELED,
        ]);
        $before = $this->snapshot($fixture);

        $response = $this->actingAs($fixture['treasury'], 'sanctum')
            ->postJson($this->url($fixture['submission']))
            ->assertConflict()
            ->assertJsonPath('code', 'payment_reassignment_conflict');

        $this->assertPrivateSafeResponse($response);
        $this->assertSnapshotUnchanged($before, $this->snapshot($fixture));
    }

    public function test_insufficient_stock_has_its_specific_private_conflict_code(): void
    {
        $fixture = $this->fixture();
        $fixture['inventory']->update(['quantity' => 1, 'reserved_quantity' => 0]);
        $fixture['product']->forceFill(['stock' => 1])->save();
        $before = $this->snapshot($fixture);

        $response = $this->actingAs($fixture['treasury'], 'sanctum')
            ->postJson($this->url($fixture['submission']))
            ->assertConflict()
            ->assertJsonPath('code', 'insufficient_stock')
            ->assertJsonPath('message', 'No hay stock suficiente para completar la reasignación.');

        $this->assertPrivateSafeResponse($response);
        $this->assertSnapshotUnchanged($before, $this->snapshot($fixture));
    }

    /** @dataProvider containerServiceStates */
    public function test_unexpected_inventory_reservation_conflict_is_translated_to_a_generic_safe_conflict(string $containerState): void
    {
        $fixture = $this->fixture();
        $abstract = TreasuryPaymentReassignmentService::class;
        $baseline = $this->captureContainerServiceState($abstract);

        try {
            $this->configureContainerServiceState($abstract, $containerState);
            $expected = $this->captureContainerServiceState($abstract);
            $mock = Mockery::mock(TreasuryPaymentReassignmentService::class);
            $mock->shouldReceive('reassignAndApprove')->once()
                ->andThrow(new InventoryReservationConflictException('sensitive internal inventory detail'));

            try {
                $this->app->instance($abstract, $mock);
                $response = $this->actingAs($fixture['treasury'], 'sanctum')
                    ->postJson($this->url($fixture['submission']))
                    ->assertConflict()
                    ->assertJsonPath('code', 'payment_reassignment_conflict');

                $this->assertPrivateSafeResponse($response);
                $this->assertStringNotContainsString('sensitive internal inventory detail', $response->getContent());
                $this->restoreContainerServiceState($abstract, $expected);
                $this->assertContainerServiceStateSame($expected, $this->captureContainerServiceState($abstract));
            } finally {
                $this->restoreContainerServiceState($abstract, $expected);
            }
        } finally {
            $this->restoreContainerServiceState($abstract, $baseline);
        }
    }

    public static function containerServiceStates(): array
    {
        return [
            'unbound and unresolved' => ['unbound_unresolved'],
            'transient and unresolved' => ['transient_unresolved'],
            'transient and resolved' => ['transient_resolved'],
            'resolved singleton' => ['singleton_resolved'],
        ];
    }

    public function test_coherent_retry_returns_the_same_case_without_new_writes(): void
    {
        $fixture = $this->fixture();
        $first = $this->actingAs($fixture['treasury'], 'sanctum')
            ->postJson($this->url($fixture['submission']))
            ->assertOk();
        $before = $this->snapshot($fixture);

        $second = $this->actingAs($fixture['treasury'], 'sanctum')
            ->postJson($this->url($fixture['submission']))
            ->assertOk();

        $this->assertSame($first->json('data.resolution.id'), $second->json('data.resolution.id'));
        $this->assertSnapshotUnchanged($before, $this->snapshot($fixture));
        $this->assertPrivateSafeResponse($second);
    }

    public function test_incoherent_partial_final_state_is_a_conflict_without_repair(): void
    {
        $fixture = $this->fixture();
        $fixture['submission']->update(['status' => PaymentSubmission::APPROVED]);
        $before = $this->snapshot($fixture);

        $response = $this->actingAs($fixture['treasury'], 'sanctum')
            ->postJson($this->url($fixture['submission']))
            ->assertConflict()
            ->assertJsonPath('code', 'payment_reassignment_conflict');

        $this->assertPrivateSafeResponse($response);
        $this->assertSnapshotUnchanged($before, $this->snapshot($fixture));
    }

    public function test_non_concurrency_query_exception_reaches_the_safe_500_handler(): void
    {
        $fixture = $this->fixture();
        $pdo = new PDOException('internal syntax detail', 1);
        $pdo->errorInfo = ['42000', 1, 'internal syntax detail'];
        $query = new QueryException('invalid internal sql', [], $pdo);
        $original = $this->app->make(TreasuryPaymentReassignmentService::class);
        $service = new class($this->app->make(InventoryService::class), $query) extends TreasuryPaymentReassignmentService
        {
            public function __construct(InventoryService $inventory, private QueryException $failure)
            {
                parent::__construct($inventory);
            }

            protected function initialFinancialState(PaymentSubmission $submission, Order $order, bool $lock = false): array
            {
                throw $this->failure;
            }
        };
        $this->app->instance(TreasuryPaymentReassignmentService::class, $service);
        $originalDebug = config('app.debug');
        $this->app['config']->set('app.debug', false);

        try {
            $response = $this->actingAs($fixture['treasury'], 'sanctum')
                ->postJson($this->url($fixture['submission']))
                ->assertStatus(500);
        } finally {
            $this->app->instance(TreasuryPaymentReassignmentService::class, $original);
            $this->app['config']->set('app.debug', $originalDebug);
        }

        $this->assertSame($originalDebug, config('app.debug'));
        $this->assertNotSame(409, $response->getStatusCode());
        $response->assertJsonMissingPath('exception')
            ->assertJsonMissingPath('trace')
            ->assertJsonMissingPath('file')
            ->assertJsonMissingPath('line');
        $this->assertStringNotContainsString('invalid internal sql', Str::lower($response->getContent()));
        $this->assertStringNotContainsString('internal syntax detail', Str::lower($response->getContent()));
    }

    private function fixture(
        string $originalStatus = InventoryReservation::EXPIRED,
        bool $expireAutomatically = false,
        bool $openResolution = true,
    ): array {
        $suffix = Str::lower(Str::random(12));
        $treasury = User::factory()->create([
            'email' => 'treasury-reassign-http-'.$suffix.'@example.test',
            'role' => 'treasury',
            'is_active' => true,
        ]);
        $customer = User::factory()->create([
            'email' => 'customer-reassign-http-'.$suffix.'@example.test',
            'role' => 'customer',
            'is_active' => true,
        ]);
        $branch = Branch::create([
            'code' => 'BRH-'.Str::upper(Str::random(8)),
            'name' => 'Sede HTTP reasignación',
            'address' => 'Dirección',
            'is_active' => true,
        ]);
        $warehouse = Warehouse::create([
            'branch_id' => $branch->id,
            'code' => 'WHH-'.Str::upper(Str::random(8)),
            'name' => 'Almacén HTTP reasignación',
            'is_active' => true,
        ]);
        $product = Product::create([
            'name' => 'Producto HTTP '.Str::random(8),
            'slug' => 'producto-http-'.Str::uuid(),
            'sku' => 'HT-'.Str::upper(Str::random(10)),
            'price' => '12.50',
            'is_active' => true,
        ]);
        $product->forceFill(['stock' => 10])->save();
        $inventory = WarehouseInventory::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity' => 10,
            'reserved_quantity' => $expireAutomatically ? 2 : 0,
        ]);
        $order = Order::create([
            'user_id' => $customer->id,
            'status' => 'pending',
            'fulfillment_status' => Order::FULFILLMENT_RESERVED,
            'total' => '25.00',
            'shipping_info' => ['address' => 'Prueba HTTP'],
            'payment_method' => 'transferencia',
            'payment_status' => 'pending',
            'paid_at' => null,
            'reserved_until' => now()->subMinute(),
            'delivery_type' => 'delivery',
            'tracking_status' => 'pending',
        ]);
        $item = OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 2,
            'price' => '12.50',
            'subtotal' => '25.00',
        ]);
        $original = InventoryReservation::forceCreate([
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'reservation_sequence' => 1,
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 2,
            'status' => $expireAutomatically ? InventoryReservation::ACTIVE : $originalStatus,
            'expires_at' => now()->subHour(),
            'released_at' => $expireAutomatically ? null : now()->subHour(),
            'idempotency_key' => 'http-reassignment-original-'.$suffix,
            'metadata' => $expireAutomatically ? [] : [
                'release_reason' => $originalStatus === InventoryReservation::EXPIRED
                    ? 'reservation_expired'
                    : 'order_canceled',
            ],
        ]);
        $submission = PaymentSubmission::create([
            'order_id' => $order->id,
            'channel' => 'yape',
            'status' => PaymentSubmission::PENDING_REVIEW,
            'expected_amount' => '25.00',
            'currency' => 'PEN',
            'operation_number' => 'HT'.Str::upper(Str::random(8)),
            'normalized_operation_number' => 'HT'.Str::upper(Str::random(10)),
            'duplicate_fingerprint' => hash('sha256', Str::uuid()->toString()),
            'declared_paid_at' => now(),
            'receiving_account_snapshot' => [],
            'submitted_by' => $customer->id,
            'submitted_at' => now(),
            'idempotency_key' => 'http-reassignment-submission-'.$suffix,
        ]);

        if ($expireAutomatically) {
            app(InventoryReservationExpirationService::class)->expireOrder($order->id);
        }

        $case = $transaction = null;
        if ($openResolution) {
            $case = app(TreasuryPaymentResolutionService::class)->open($submission->id, $treasury->id);
            $transaction = PaymentTransaction::where('payment_submission_id', $submission->id)->firstOrFail();
        }

        return compact(
            'treasury',
            'customer',
            'order',
            'submission',
            'case',
            'transaction',
            'item',
            'inventory',
            'product',
            'original',
        ) + [
            'secondary_order_ids' => [],
            'secondary_submission_ids' => [],
            'secondary_transaction_ids' => [],
            'secondary_case_ids' => [],
        ];
    }

    private function applyAvailabilityAnomaly(array &$fixture, string $anomaly): void
    {
        $submissionId = $fixture['submission']->id;
        $transactionId = $fixture['transaction']->id;
        $caseId = $fixture['case']->id;
        $orderId = $fixture['order']->id;

        $updates = match ($anomaly) {
            'submission_status' => ['payment_submissions', $submissionId, ['status' => PaymentSubmission::OBSERVED]],
            'submission_reviewed_at' => ['payment_submissions', $submissionId, ['reviewed_at' => null]],
            'submission_review' => ['payment_submissions', $submissionId, ['decision_reason' => 'incompatible_reason']],
            'submission_amount' => ['payment_submissions', $submissionId, ['expected_amount' => '24.99']],
            'submission_currency' => ['payment_submissions', $submissionId, ['currency' => 'USD']],
            'submission_channel' => ['payment_submissions', $submissionId, ['channel' => 'plin']],
            'order_paid' => ['orders', $orderId, ['payment_status' => 'approved']],
            'order_paid_at' => ['orders', $orderId, ['paid_at' => now()]],
            'order_terminal' => ['orders', $orderId, ['status' => 'delivered']],
            'transaction_status' => ['payment_transactions', $transactionId, ['status' => PaymentTransaction::PENDING]],
            'transaction_amount' => ['payment_transactions', $transactionId, ['amount' => '24.99']],
            'transaction_currency' => ['payment_transactions', $transactionId, ['currency' => 'USD']],
            'transaction_method' => ['payment_transactions', $transactionId, ['payment_method' => 'cod']],
            'transaction_type' => ['payment_transactions', $transactionId, ['transaction_type' => PaymentTransaction::REFUND]],
            'transaction_channel' => ['payment_transactions', $transactionId, ['collection_method' => 'plin']],
            'transaction_key' => ['payment_transactions', $transactionId, ['idempotency_key' => 'incompatible-received-'.$transactionId]],
            'transaction_scope' => ['payment_transactions', $transactionId, ['approved_scope_key' => PaymentTransaction::approvedScopeKeyForOrder($orderId)]],
            'transaction_confirmed_at' => ['payment_transactions', $transactionId, ['confirmed_at' => null]],
            'case_status' => ['payment_resolution_cases', $caseId, ['status' => PaymentResolutionCase::REASSIGNED]],
            'case_type' => ['payment_resolution_cases', $caseId, ['type' => PaymentResolutionCase::STOCK_REASSIGNMENT]],
            'case_amount' => ['payment_resolution_cases', $caseId, ['amount' => '24.99']],
            'case_currency' => ['payment_resolution_cases', $caseId, ['currency' => 'USD']],
            'case_key' => ['payment_resolution_cases', $caseId, ['idempotency_key' => 'incompatible-case-'.$caseId]],
            'case_funds_received_at' => ['payment_resolution_cases', $caseId, ['funds_received_at' => null]],
            'case_requested_at' => ['payment_resolution_cases', $caseId, ['requested_at' => now()->addMinute()]],
            default => null,
        };

        if ($updates) {
            DB::table($updates[0])->where('id', $updates[1])->update($updates[2]);

            return;
        }

        if (in_array($anomaly, ['transaction_actor', 'case_actor', 'history_actor', 'resolution_history_actor'], true)) {
            $other = User::factory()->create([
                'email' => 'availability-actor-'.Str::uuid().'@example.test',
                'role' => 'treasury',
                'is_active' => true,
            ]);
            $target = match ($anomaly) {
                'transaction_actor' => ['payment_transactions', $transactionId, 'confirmed_by'],
                'case_actor' => ['payment_resolution_cases', $caseId, 'requested_by'],
                'history_actor' => ['payment_submission_histories', $this->openingSubmissionHistoryId($submissionId), 'actor_id'],
                default => ['payment_resolution_histories', $this->openingResolutionHistoryId($caseId), 'actor_id'],
            };
            DB::table($target[0])->where('id', $target[1])->update([$target[2] => $other->id]);

            return;
        }

        if ($anomaly === 'transaction_absent') {
            DB::table('payment_resolution_cases')->where('id', $caseId)->update(['received_payment_transaction_id' => null]);
            DB::table('payment_transactions')->where('id', $transactionId)->delete();

            return;
        }

        if ($anomaly === 'transaction_cross_order') {
            $otherOrder = $fixture['order']->replicate();
            $otherOrder->save();
            $fixture['secondary_order_ids'][] = $otherOrder->id;
            DB::table('payment_transactions')->where('id', $transactionId)->update(['order_id' => $otherOrder->id]);

            return;
        }

        if ($anomaly === 'transaction_cross_submission') {
            $otherSubmission = $this->secondarySubmission($fixture);
            DB::table('payment_transactions')->where('id', $transactionId)->update(['payment_submission_id' => $otherSubmission->id]);

            return;
        }

        if ($anomaly === 'case_absent') {
            DB::table('payment_resolution_histories')->where('payment_resolution_case_id', $caseId)->delete();
            DB::table('payment_resolution_cases')->where('id', $caseId)->delete();

            return;
        }

        if ($anomaly === 'case_transaction_link') {
            DB::table('payment_resolution_cases')->where('id', $caseId)->update([
                'received_payment_transaction_id' => $this->secondaryTransaction($fixture)->id,
            ]);

            return;
        }

        if ($anomaly === 'case_cross_submission') {
            DB::table('payment_resolution_cases')->where('id', $caseId)->update([
                'payment_submission_id' => $this->secondarySubmission($fixture)->id,
            ]);

            return;
        }

        $submissionHistoryId = $this->openingSubmissionHistoryId($submissionId);
        $resolutionHistoryId = $this->openingResolutionHistoryId($caseId);
        if ($anomaly === 'submission_history_absent') {
            DB::table('payment_submission_histories')->where('id', $submissionHistoryId)->delete();
        } elseif ($anomaly === 'submission_history_duplicate') {
            $this->duplicateRow('payment_submission_histories', $submissionHistoryId);
        } elseif ($anomaly === 'resolution_history_absent') {
            DB::table('payment_resolution_histories')->where('id', $resolutionHistoryId)->delete();
        } elseif ($anomaly === 'resolution_history_duplicate') {
            $this->duplicateRow('payment_resolution_histories', $resolutionHistoryId);
        } elseif ($anomaly === 'history_transition') {
            DB::table('payment_submission_histories')->where('id', $submissionHistoryId)->update(['from_status' => PaymentSubmission::APPROVED]);
        } elseif ($anomaly === 'history_event') {
            DB::table('payment_submission_histories')->where('id', $submissionHistoryId)->update(['event' => 'observed']);
        } elseif ($anomaly === 'history_timestamp') {
            DB::table('payment_submission_histories')->where('id', $submissionHistoryId)->update(['occurred_at' => now()->addMinute()]);
        } elseif ($anomaly === 'history_metadata') {
            DB::table('payment_submission_histories')->where('id', $submissionHistoryId)->update([
                'safe_metadata' => json_encode(['currency' => 'PEN', 'unexpected' => true], JSON_THROW_ON_ERROR),
            ]);
        } elseif ($anomaly === 'resolution_history_transition') {
            DB::table('payment_resolution_histories')->where('id', $resolutionHistoryId)->update(['from_status' => PaymentResolutionCase::OPEN]);
        } elseif ($anomaly === 'resolution_history_timestamp') {
            DB::table('payment_resolution_histories')->where('id', $resolutionHistoryId)->update(['occurred_at' => now()->addMinute()]);
        } elseif ($anomaly === 'resolution_history_metadata') {
            DB::table('payment_resolution_histories')->where('id', $resolutionHistoryId)->update([
                'safe_metadata' => json_encode(['currency' => 'PEN', 'amount' => '24.99'], JSON_THROW_ON_ERROR),
            ]);
        } elseif ($anomaly === 'refund') {
            PaymentRefund::forceCreate([
                'payment_resolution_case_id' => $caseId,
                'amount' => $fixture['case']->amount,
                'currency' => 'PEN',
                'status' => PaymentRefund::PENDING,
                'reason' => 'incompatible_refund',
                'requested_by' => $fixture['treasury']->id,
                'requested_at' => now(),
                'idempotency_key' => 'availability-refund-'.Str::uuid(),
            ]);
        } elseif ($anomaly === 'manual_cancellation') {
            DB::table('orders')->where('id', $orderId)->update([
                'status' => 'canceled',
                'fulfillment_status' => Order::FULFILLMENT_CANCELED,
                'tracking_status' => 'canceled',
            ]);
            DB::table('inventory_reservations')->where('id', $fixture['original']->id)->update([
                'status' => InventoryReservation::RELEASED,
                'released_at' => now(),
                'metadata' => json_encode(['release_reason' => 'order_canceled'], JSON_THROW_ON_ERROR),
            ]);
        } else {
            $this->fail('Unknown availability anomaly: '.$anomaly);
        }
    }

    private function applyNonCanceledReservationAnomaly(array &$fixture, string $anomaly): void
    {
        if ($anomaly === 'missing_original') {
            DB::table('inventory_reservations')->where('id', $fixture['original']->id)->delete();

            return;
        }

        if ($anomaly === 'quantity') {
            DB::table('inventory_reservations')->where('id', $fixture['original']->id)->update(['quantity' => 1]);

            return;
        }

        if ($anomaly === 'status') {
            DB::table('inventory_reservations')->where('id', $fixture['original']->id)->update([
                'status' => InventoryReservation::CONSUMED,
                'consumed_at' => now(),
            ]);

            return;
        }

        if ($anomaly === 'incomplete_set') {
            OrderItem::create([
                'order_id' => $fixture['order']->id,
                'product_id' => $fixture['product']->id,
                'warehouse_id' => $fixture['item']->warehouse_id,
                'quantity' => 1,
                'price' => '25.00',
                'subtotal' => '25.00',
            ]);

            return;
        }

        $otherOrder = $fixture['order']->replicate();
        $otherOrder->save();
        $fixture['secondary_order_ids'][] = $otherOrder->id;

        if ($anomaly === 'cross_order') {
            DB::table('inventory_reservations')->where('id', $fixture['original']->id)->update(['order_id' => $otherOrder->id]);

            return;
        }

        $otherItem = $fixture['item']->replicate();
        $otherItem->order_id = $otherOrder->id;
        $otherItem->save();

        if ($anomaly === 'cross_item') {
            DB::table('inventory_reservations')->where('id', $fixture['original']->id)->update(['order_item_id' => $otherItem->id]);

            return;
        }

        if ($anomaly === 'crossed_evidence') {
            InventoryReservation::forceCreate([
                'order_id' => $otherOrder->id,
                'order_item_id' => $fixture['item']->id,
                'reservation_sequence' => 2,
                'product_id' => $fixture['product']->id,
                'warehouse_id' => $fixture['item']->warehouse_id,
                'quantity' => $fixture['item']->quantity,
                'status' => InventoryReservation::EXPIRED,
                'expires_at' => now()->subHour(),
                'released_at' => now()->subHour(),
                'idempotency_key' => 'crossed-reassignment-evidence-'.Str::uuid(),
                'metadata' => ['release_reason' => 'reservation_expired'],
            ]);

            return;
        }

        $this->fail('Unknown non-canceled reservation anomaly: '.$anomaly);
    }

    private function secondarySubmission(array &$fixture): PaymentSubmission
    {
        $otherOrder = $fixture['order']->replicate();
        $otherOrder->save();
        $fixture['secondary_order_ids'][] = $otherOrder->id;
        $copy = $fixture['submission']->replicate();
        $copy->order_id = $otherOrder->id;
        $copy->status = PaymentSubmission::PENDING_REVIEW;
        $copy->operation_number = 'OTHER'.Str::upper(Str::random(8));
        $copy->normalized_operation_number = 'OTHER'.Str::upper(Str::random(10));
        $copy->duplicate_fingerprint = hash('sha256', Str::uuid()->toString());
        $copy->idempotency_key = 'availability-submission-'.Str::uuid();
        $copy->reviewed_by = null;
        $copy->reviewed_at = null;
        $copy->decision_reason = null;
        $copy->save();
        $fixture['secondary_submission_ids'][] = $copy->id;

        return $copy;
    }

    private function secondaryTransaction(array &$fixture): PaymentTransaction
    {
        $attributes = $fixture['transaction']->getAttributes();
        unset($attributes['id'], $attributes['created_at'], $attributes['updated_at']);
        $attributes['payment_submission_id'] = null;
        $attributes['idempotency_key'] = 'availability-transaction-'.Str::uuid();

        $transaction = PaymentTransaction::forceCreate($attributes);
        $fixture['secondary_transaction_ids'][] = $transaction->id;

        return $transaction;
    }

    private function openingSubmissionHistoryId(int $submissionId): int
    {
        return (int) PaymentSubmissionHistory::query()
            ->where('payment_submission_id', $submissionId)
            ->where('event', 'resolution_opened')
            ->value('id');
    }

    private function openingResolutionHistoryId(int $caseId): int
    {
        return (int) PaymentResolutionHistory::query()
            ->where('payment_resolution_case_id', $caseId)
            ->where('event', 'opened')
            ->value('id');
    }

    private function duplicateRow(string $table, int $id): void
    {
        $row = (array) DB::table($table)->where('id', $id)->first();
        unset($row['id']);
        DB::table($table)->insert($row);
    }

    private function snapshot(array $fixture): array
    {
        $orderIds = collect([$fixture['order']->id])
            ->merge($fixture['secondary_order_ids'] ?? [])->map(fn ($id) => (int) $id)->unique()->values();
        $submissionIds = collect([$fixture['submission']->id])
            ->merge($fixture['secondary_submission_ids'] ?? [])->map(fn ($id) => (int) $id)->unique()->values();
        $transactionIds = DB::table('payment_transactions')
            ->where(function ($query) use ($orderIds, $submissionIds, $fixture) {
                $query->whereIn('order_id', $orderIds)
                    ->orWhereIn('payment_submission_id', $submissionIds)
                    ->orWhereIn('id', $fixture['secondary_transaction_ids'] ?? []);
            })->pluck('id')->map(fn ($id) => (int) $id)->unique()->values();
        $caseIds = DB::table('payment_resolution_cases')
            ->where(function ($query) use ($submissionIds, $transactionIds, $fixture) {
                $query->whereIn('payment_submission_id', $submissionIds)
                    ->orWhereIn('received_payment_transaction_id', $transactionIds)
                    ->orWhereIn('id', $fixture['secondary_case_ids'] ?? []);
            })->pluck('id')->map(fn ($id) => (int) $id)->unique()->values();
        $itemIds = DB::table('order_items')->whereIn('order_id', $orderIds)->pluck('id')->map(fn ($id) => (int) $id)->values();
        $productIds = DB::table('order_items')->whereIn('id', $itemIds)->pluck('product_id')
            ->push($fixture['product']->id)->map(fn ($id) => (int) $id)->unique()->values();

        $definitions = [
            'orders' => ['orders', fn ($query) => $query->whereIn('id', $orderIds)],
            'submissions' => ['payment_submissions', fn ($query) => $query->whereIn('id', $submissionIds)],
            'transactions' => ['payment_transactions', fn ($query) => $query->whereIn('id', $transactionIds)],
            'cases' => ['payment_resolution_cases', fn ($query) => $query->whereIn('id', $caseIds)],
            'submission_histories' => ['payment_submission_histories', fn ($query) => $query->whereIn('payment_submission_id', $submissionIds)],
            'resolution_histories' => ['payment_resolution_histories', fn ($query) => $query->whereIn('payment_resolution_case_id', $caseIds)],
            'refunds' => ['payment_refunds', fn ($query) => $query->whereIn('payment_resolution_case_id', $caseIds)],
            'items' => ['order_items', fn ($query) => $query->whereIn('id', $itemIds)],
            'reservations' => ['inventory_reservations', fn ($query) => $query->whereIn('order_id', $orderIds)->orWhereIn('order_item_id', $itemIds)],
            'inventories' => ['warehouse_inventories', fn ($query) => $query->whereIn('product_id', $productIds)],
            'products' => ['products', fn ($query) => $query->whereIn('id', $productIds)],
            'movements' => ['inventory_movements', fn ($query) => $query->where('reference_type', 'order')->whereIn('reference_id', $orderIds->map(fn ($id) => (string) $id))],
            'fulfillment_histories' => ['order_fulfillment_histories', fn ($query) => $query->whereIn('order_id', $orderIds)],
            'handling_processes' => ['order_handling_processes', fn ($query) => $query->whereIn('order_id', $orderIds)],
        ];

        $public = [];
        $sensitive = [];
        foreach ($definitions as $name => [$table, $scope]) {
            $rows = $this->snapshotRows($table, $scope);
            $public[$name] = $rows['public'];
            $sensitive[$name] = $rows['sensitive'];
        }

        return compact('public', 'sensitive');
    }

    /** @return array{public: array, sensitive: array} */
    private function snapshotRows(string $table, callable $scope): array
    {
        $query = DB::table($table);
        $scope($query);

        $public = [];
        $sensitive = [];
        foreach ($query->orderBy('id')->get() as $row) {
            $values = (array) $row;
            $rowKey = (string) ($values['id'] ?? count($public));
            foreach ($values as $key => $value) {
                if ($this->isSensitiveSnapshotKey((string) $key)) {
                    $sensitive[$rowKey.'.'.$key] = $this->sensitiveDigest($value);
                    unset($values[$key]);
                }
            }
            $public[] = $this->normalizeSnapshotValue($values);
        }

        ksort($sensitive);

        return compact('public', 'sensitive');
    }

    private function normalizeSnapshotValue(mixed $value, ?string $key = null): mixed
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $value = $this->normalizeSnapshotValue($decoded, $key);
            }
        }

        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn ($item) => $this->normalizeSnapshotValue($item), $value);
        }

        ksort($value);
        foreach ($value as $childKey => $childValue) {
            $value[$childKey] = $this->normalizeSnapshotValue($childValue, (string) $childKey);
        }

        return $value;
    }

    private function isSensitiveSnapshotKey(string $key): bool
    {
        return in_array($key, [
            'operation_number',
            'normalized_operation_number',
            'duplicate_fingerprint',
            'idempotency_key',
            'approved_scope_key',
            'receiving_account_snapshot',
            'metadata',
            'safe_metadata',
            'external_reference',
        ], true);
    }

    private function sensitiveDigest(mixed $value): string
    {
        if (is_string($value) && preg_match('/^\s*[\[{]/', $value) === 1) {
            try {
                $value = json_decode($value, false, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                // Preserve malformed or non-JSON strings with their exact type.
            }
        }

        return hash('sha256', json_encode(
            $this->canonicalSensitiveValue($value),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
        ));
    }

    private function canonicalSensitiveValue(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $properties = get_object_vars($value);
            ksort($properties);

            return ['__object' => array_map(fn ($item) => $this->canonicalSensitiveValue($item), $properties)];
        }

        if (is_array($value)) {
            if (array_is_list($value)) {
                return ['__list' => array_map(fn ($item) => $this->canonicalSensitiveValue($item), $value)];
            }
            ksort($value);

            return ['__object' => array_map(fn ($item) => $this->canonicalSensitiveValue($item), $value)];
        }

        return ['__type' => get_debug_type($value), '__value' => $value];
    }

    private function assertSnapshotUnchanged(array $before, array $after): void
    {
        $this->assertSame($before['public'], $after['public']);
        $this->assertTrue(
            hash_equals($this->sensitiveDigest($before['sensitive']), $this->sensitiveDigest($after['sensitive'])),
            'Sensitive snapshot changed'
        );
    }

    private function assertUniqueConstraintViolation(QueryException $exception, string $constraint, string $column): void
    {
        $driver = DB::connection()->getDriverName();
        $errorInfo = $exception->errorInfo ?? [];
        $message = Str::lower($exception->getMessage());

        if ($driver === 'sqlite') {
            $this->assertSame(19, (int) ($errorInfo[1] ?? $exception->getCode()));
            $this->assertStringContainsString('unique', $message);
            $this->assertStringContainsString(Str::lower($column), $message);

            return;
        }

        $this->assertSame('mysql', $driver);
        $this->assertSame('23000', (string) ($errorInfo[0] ?? $exception->getCode()));
        $this->assertSame(1062, (int) ($errorInfo[1] ?? 0));
        $this->assertStringContainsString('duplicate entry', $message);
        $this->assertStringContainsString(Str::lower($constraint), $message);
    }

    /** @return array<string, mixed> */
    private function captureContainerServiceState(string $abstract): array
    {
        $bindings = $this->containerProperty('bindings');
        $instances = $this->containerProperty('instances');
        $resolved = $this->containerProperty('resolved');
        $aliases = $this->containerProperty('aliases');
        $abstractAliases = $this->containerProperty('abstractAliases');

        return [
            'binding_exists' => array_key_exists($abstract, $bindings),
            'binding' => $bindings[$abstract] ?? null,
            'instance_exists' => array_key_exists($abstract, $instances),
            'instance' => $instances[$abstract] ?? null,
            'resolved_exists' => array_key_exists($abstract, $resolved),
            'resolved' => $resolved[$abstract] ?? false,
            'aliases' => array_filter($aliases, fn ($target) => $target === $abstract),
            'abstract_aliases_exists' => array_key_exists($abstract, $abstractAliases),
            'abstract_aliases' => $abstractAliases[$abstract] ?? [],
            'app_debug' => config('app.debug'),
        ];
    }

    private function configureContainerServiceState(string $abstract, string $state): void
    {
        $empty = [
            'binding_exists' => false,
            'binding' => null,
            'instance_exists' => false,
            'instance' => null,
            'resolved_exists' => false,
            'resolved' => false,
            'aliases' => [],
            'abstract_aliases_exists' => false,
            'abstract_aliases' => [],
            'app_debug' => config('app.debug'),
        ];
        $this->restoreContainerServiceState($abstract, $empty);

        if ($state === 'unbound_unresolved') {
            return;
        }

        if ($state === 'transient_unresolved' || $state === 'transient_resolved') {
            $this->app->bind($abstract, $abstract, false);
        } elseif ($state === 'singleton_resolved') {
            $this->app->singleton($abstract, $abstract);
        } else {
            $this->fail('Unknown container state fixture.');
        }

        if (in_array($state, ['transient_resolved', 'singleton_resolved'], true)) {
            $this->app->make($abstract);
        }
    }

    /** @param  array<string, mixed>  $state */
    private function restoreContainerServiceState(string $abstract, array $state): void
    {
        $bindings = $this->containerProperty('bindings');
        $instances = $this->containerProperty('instances');
        $resolved = $this->containerProperty('resolved');
        $aliases = $this->containerProperty('aliases');
        $abstractAliases = $this->containerProperty('abstractAliases');

        if ($state['binding_exists']) {
            $bindings[$abstract] = $state['binding'];
        } else {
            unset($bindings[$abstract]);
        }
        if ($state['instance_exists']) {
            $instances[$abstract] = $state['instance'];
        } else {
            unset($instances[$abstract]);
        }
        if ($state['resolved_exists']) {
            $resolved[$abstract] = $state['resolved'];
        } else {
            unset($resolved[$abstract]);
        }
        foreach ($aliases as $alias => $target) {
            if ($target === $abstract) {
                unset($aliases[$alias]);
            }
        }
        foreach ($state['aliases'] as $alias => $target) {
            $aliases[$alias] = $target;
        }
        if ($state['abstract_aliases_exists']) {
            $abstractAliases[$abstract] = $state['abstract_aliases'];
        } else {
            unset($abstractAliases[$abstract]);
        }

        $this->setContainerProperty('bindings', $bindings);
        $this->setContainerProperty('instances', $instances);
        $this->setContainerProperty('resolved', $resolved);
        $this->setContainerProperty('aliases', $aliases);
        $this->setContainerProperty('abstractAliases', $abstractAliases);
        config(['app.debug' => $state['app_debug']]);
    }

    /**
     * @param  array<string, mixed>  $expected
     * @param  array<string, mixed>  $actual
     */
    private function assertContainerServiceStateSame(array $expected, array $actual): void
    {
        foreach (['binding_exists', 'instance_exists', 'resolved_exists', 'resolved', 'abstract_aliases_exists', 'app_debug'] as $key) {
            $this->assertSame($expected[$key], $actual[$key], 'Container scalar state changed.');
        }
        $this->assertTrue($expected['binding'] === $actual['binding'], 'Container binding changed.');
        $this->assertTrue($expected['instance'] === $actual['instance'], 'Container instance changed.');
        $this->assertSame($expected['aliases'], $actual['aliases'], 'Container aliases changed.');
        $this->assertSame($expected['abstract_aliases'], $actual['abstract_aliases'], 'Container abstract aliases changed.');
    }

    /** @return array<mixed> */
    private function containerProperty(string $name): array
    {
        $property = $this->containerReflectionProperty($name);

        return $property->getValue($this->app);
    }

    /** @param  array<mixed>  $value */
    private function setContainerProperty(string $name, array $value): void
    {
        $this->containerReflectionProperty($name)->setValue($this->app, $value);
    }

    private function containerReflectionProperty(string $name): \ReflectionProperty
    {
        $class = new \ReflectionObject($this->app);
        while (! $class->hasProperty($name) && ($class = $class->getParentClass())) {
        }
        if (! $class) {
            throw new \LogicException('Container property is unavailable.');
        }
        $property = $class->getProperty($name);
        $property->setAccessible(true);

        return $property;
    }

    private function assertAvailabilityResponseDoesNotExposeInternalData(string $content): void
    {
        foreach ([
            'normalized_operation_number',
            'duplicate_fingerprint',
            'idempotency_key',
            'approved_scope_key',
            'resolution_case_id',
            'reservation_sequence',
            'metadata',
            'exception',
            'trace',
            'file',
            'line',
            'sql',
            'bindings',
        ] as $sensitive) {
            $this->assertStringNotContainsString('"'.$sensitive.'"', Str::lower($content));
        }
    }

    private function assertPrivateSafeResponse($response): void
    {
        $response->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Pragma', 'no-cache');

        foreach ([
            'operation_number',
            'normalized_operation_number',
            'fingerprint',
            'idempotency_key',
            'approved_scope_key',
            'metadata',
            'snapshot',
            'receiving_account_snapshot',
            'reservation_sequence',
            'exception',
            'trace',
            'file',
            'line',
            'sql',
            'bindings',
        ] as $sensitive) {
            $this->assertStringNotContainsString('"'.$sensitive.'"', Str::lower($response->getContent()));
        }
    }

    private function url(PaymentSubmission $submission): string
    {
        return '/api/v1/treasury/payment-submissions/'.$submission->id.'/resolution/reassign';
    }
}
