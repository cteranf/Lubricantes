<?php

namespace Tests\Feature;

use App\Http\Resources\TreasuryPaymentSubmissionHistoryResource;
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
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TreasuryPaymentReviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_an_active_treasury_user_can_access_the_review_queue(): void
    {
        $submission = $this->submission();
        $url = '/api/v1/treasury/payment-submissions';
        $this->getJson($url)->assertUnauthorized();
        Sanctum::actingAs(User::factory()->create(['role' => 'customer']));
        $this->getJson($url)->assertForbidden();
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->getJson($url)->assertForbidden();
        Sanctum::actingAs(User::factory()->create(['role' => 'treasury', 'is_active' => false]));
        $this->getJson($url)->assertForbidden()->assertJsonPath('code', 'account_inactive');
        Sanctum::actingAs($this->treasury());
        $this->getJson($url)->assertOk()->assertJsonPath('data.0.id', $submission->id);
    }

    public function test_every_treasury_route_enforces_the_same_actor_matrix_and_safe_missing_ids(): void
    {
        $submission = $this->submission();
        $routes = [
            ['GET', '/api/v1/treasury/payment-submissions'],
            ['GET', '/api/v1/treasury/payment-submissions/'.$submission->id],
            ['GET', '/api/v1/treasury/payment-submissions/'.$submission->id.'/history'],
            ['PATCH', '/api/v1/treasury/payment-submissions/'.$submission->id.'/observe'],
            ['PATCH', '/api/v1/treasury/payment-submissions/'.$submission->id.'/reject'],
        ];
        foreach ($routes as [$method, $url]) {
            $this->app['auth']->forgetGuards();
            $this->json($method, $url, $method === 'PATCH' ? ['reason' => 'Motivo válido'] : [])->assertUnauthorized();
            Sanctum::actingAs(User::factory()->create(['role' => 'customer']));
            $this->json($method, $url, $method === 'PATCH' ? ['reason' => 'Motivo válido'] : [])->assertForbidden();
            Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
            $this->json($method, $url, $method === 'PATCH' ? ['reason' => 'Motivo válido'] : [])->assertForbidden();
            Sanctum::actingAs(User::factory()->create(['role' => 'treasury', 'is_active' => false]));
            $this->json($method, $url, $method === 'PATCH' ? ['reason' => 'Motivo válido'] : [])->assertForbidden()->assertJsonPath('code', 'account_inactive');
            Sanctum::actingAs($this->treasury());
            $response = $this->json($method, $url, []);
            $this->assertContains($response->getStatusCode(), [200, 422]);
        }
        Sanctum::actingAs($this->treasury());
        foreach (['', '/history', '/observe', '/reject'] as $suffix) {
            $response = $suffix === '/observe' || $suffix === '/reject'
                ? $this->patchJson('/api/v1/treasury/payment-submissions/999999'.$suffix, ['reason' => 'Motivo válido'])
                : $this->getJson('/api/v1/treasury/payment-submissions/999999'.$suffix);
            $response->assertNotFound();
            foreach (['exception', 'trace', 'file', 'line', 'sql', 'PaymentSubmission'] as $sensitive) {
                $this->assertStringNotContainsString($sensitive, $response->getContent());
            }
        }
    }

    public function test_queue_is_paginated_filtered_prioritized_and_does_not_expose_sensitive_fields(): void
    {
        Carbon::setTestNow('2026-09-23 10:00:00');
        try {
            $terminal = $this->submission(['status' => PaymentSubmission::REJECTED, 'submitted_at' => now()->subHours(3)]);
            $observed = $this->submission(['status' => PaymentSubmission::OBSERVED, 'submitted_at' => now()->subHours(2), 'channel' => 'plin']);
            $pendingOld = $this->submission(['status' => PaymentSubmission::PENDING_REVIEW, 'submitted_at' => now()->subHour(), 'normalized_operation_number' => 'OPERACIONOLD']);
            $pendingNew = $this->submission(['status' => PaymentSubmission::PENDING_REVIEW, 'submitted_at' => now()->subMinutes(10), 'channel' => 'bank_transfer']);
            Sanctum::actingAs($this->treasury());
            $response = $this->getJson('/api/v1/treasury/payment-submissions?per_page=2')
                ->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Pragma', 'no-cache');
            $response->assertJsonPath('meta.total', 4)
                ->assertJsonPath('meta.per_page', 2)
                ->assertJsonPath('data.0.id', $pendingOld->id)
                ->assertJsonPath('data.1.id', $pendingNew->id)
                ->assertJsonMissingPath('data.0.operation_number')
                ->assertJsonMissingPath('data.0.duplicate_fingerprint')
                ->assertJsonMissingPath('data.0.idempotency_key')
                ->assertJsonMissingPath('data.0.receiving_account_snapshot');
            $this->getJson('/api/v1/treasury/payment-submissions?status=observed&channel=plin&search=op-eracion')
                ->assertOk()->assertJsonCount(0, 'data');
            $this->getJson('/api/v1/treasury/payment-submissions?per_page=101')->assertUnprocessable();
            $this->getJson('/api/v1/treasury/payment-submissions?status=invalid')->assertUnprocessable();
            $this->assertNotNull($terminal);
            $this->assertNotNull($observed);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_queue_filters_dates_account_and_search_values_without_arbitrary_columns(): void
    {
        Carbon::setTestNow('2026-09-23 12:00:00');
        try {
            $early = $this->submission(['submitted_at' => now()->subDays(2), 'receiving_account_snapshot' => ['id' => 10, 'code' => 'A', 'display_name' => 'A']]);
            $today = $this->submission(['submitted_at' => now()->startOfDay(), 'normalized_operation_number' => 'OPERACION999', 'receiving_account_snapshot' => ['id' => 20, 'code' => 'B', 'display_name' => 'B']]);
            Sanctum::actingAs($this->treasury());
            $this->getJson('/api/v1/treasury/payment-submissions?date_from='.now()->toDateString().'&date_to='.now()->toDateString().'&receiving_account_id=20')
                ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $today->id);
            $this->getJson('/api/v1/treasury/payment-submissions?search=%23'.$early->order_id)->assertOk()->assertJsonPath('data.0.id', $early->id);
            $this->getJson('/api/v1/treasury/payment-submissions?search=operacion-999')->assertOk()->assertJsonPath('data.0.id', $today->id);
            $this->getJson('/api/v1/treasury/payment-submissions?search=%21%40%23')->assertOk()->assertJsonCount(0, 'data');
            $this->getJson('/api/v1/treasury/payment-submissions?date_from=2026-10-02&date_to=2026-10-01')->assertUnprocessable();
            $this->getJson('/api/v1/treasury/payment-submissions?receiving_account_id=no')->assertUnprocessable();
            $this->getJson('/api/v1/treasury/payment-submissions?unknown=approved')->assertOk()->assertJsonCount(2, 'data');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_queue_masks_short_operations_while_treasury_detail_keeps_the_authorized_value(): void
    {
        $submission = $this->submission([
            'operation_number' => '1111',
            'normalized_operation_number' => '1111',
        ]);
        Sanctum::actingAs($this->treasury());

        $list = $this->getJson('/api/v1/treasury/payment-submissions')->assertOk()
            ->assertJsonPath('data.0.id', $submission->id)
            ->assertJsonPath('data.0.operation_number_masked', '••11')
            ->assertJsonMissingPath('data.0.operation_number');
        $this->assertStringNotContainsString('1111', $list->getContent());

        $this->getJson('/api/v1/treasury/payment-submissions/'.$submission->id)
            ->assertOk()
            ->assertJsonPath('data.operation_number', '1111');
        $this->getJson('/api/v1/treasury/payment-submissions/'.$submission->id.'/history')
            ->assertOk()
            ->assertJsonMissingPath('data.0.operation_number');
    }

    public function test_detail_and_history_are_safe_chronological_and_control_missing_resources(): void
    {
        $submission = $this->submission();
        $actor = $this->treasury();
        PaymentSubmissionHistory::create(['payment_submission_id' => $submission->id, 'event' => 'submitted', 'to_status' => PaymentSubmission::PENDING_REVIEW, 'actor_id' => $actor->id, 'safe_metadata' => ['previous_expires_at' => '2026-01-01T00:00:00+00:00', 'idempotency_key' => 'secret'], 'occurred_at' => now()->subMinute()]);
        PaymentSubmissionHistory::create(['payment_submission_id' => $submission->id, 'event' => 'observed', 'from_status' => PaymentSubmission::PENDING_REVIEW, 'to_status' => PaymentSubmission::OBSERVED, 'actor_id' => $actor->id, 'reason' => 'Revisar', 'safe_metadata' => ['operation_number' => 'secret'], 'occurred_at' => now()]);
        Sanctum::actingAs($actor);
        $this->getJson('/api/v1/treasury/payment-submissions/'.$submission->id)
            ->assertOk()->assertJsonPath('data.operation_number', '000123456')
            ->assertJsonMissingPath('data.duplicate_fingerprint')->assertJsonMissingPath('data.idempotency_key')
            ->assertJsonMissingPath('data.receiving_account_snapshot.qr_path');
        $history = $this->getJson('/api/v1/treasury/payment-submissions/'.$submission->id.'/history')->assertOk();
        $history->assertJsonPath('data.0.event', 'submitted')->assertJsonMissingPath('data.0.safe_metadata');
        $this->getJson('/api/v1/treasury/payment-submissions/999999')->assertNotFound();
    }

    public function test_observe_records_one_atomic_transition_without_operational_side_effects(): void
    {
        [$submission, $order, $reservation, $inventory] = $this->submissionWithInventory();
        $treasury = $this->treasury();
        $before = [
            [$order->fresh()->payment_status, $order->fresh()->paid_at?->format('c'), $order->fresh()->reserved_until?->format('c')],
            [$reservation->fresh()->status, $reservation->fresh()->expires_at?->format('c')],
            $inventory->fresh()->only(['quantity', 'reserved_quantity']),
        ];
        Sanctum::actingAs($treasury);
        $this->patchJson('/api/v1/treasury/payment-submissions/'.$submission->id.'/observe', ['reason' => 'No se pudo localizar la operación.'])
            ->assertOk()->assertJsonPath('data.status', PaymentSubmission::OBSERVED);
        $stored = $submission->fresh();
        $this->assertSame($treasury->id, $stored->reviewed_by);
        $this->assertSame('No se pudo localizar la operación.', $stored->decision_reason);
        $this->assertSame(1, PaymentSubmissionHistory::count());
        $this->assertSame($before[0][0], $order->fresh()->payment_status);
        $this->assertSame($before[0][1], $order->fresh()->paid_at?->format('c'));
        $this->assertGreaterThanOrEqual($before[0][2], $order->fresh()->reserved_until?->format('c'));
        $this->assertSame($reservation->fresh()->expires_at?->format('c'), $order->fresh()->reserved_until?->format('c'));
        $this->assertSame($before[1][0], $reservation->fresh()->status);
        $this->assertSame($before[2], $inventory->fresh()->only(['quantity', 'reserved_quantity']));
        $this->assertSame(0, PaymentTransaction::count());
        $this->assertSame(0, InventoryMovement::count());
        $this->patchJson('/api/v1/treasury/payment-submissions/'.$submission->id.'/observe', ['reason' => 'Segundo intento'])->assertConflict();
        $this->assertSame(1, PaymentSubmissionHistory::count());
    }

    public function test_reject_allows_pending_or_observed_but_never_terminal_or_hostile_payloads(): void
    {
        $pending = $this->submission();
        $observed = $this->submission(['status' => PaymentSubmission::OBSERVED]);
        $terminal = $this->submission(['status' => PaymentSubmission::REJECTED]);
        Sanctum::actingAs($this->treasury());
        $this->patchJson('/api/v1/treasury/payment-submissions/'.$pending->id.'/reject', ['reason' => 'Importe no coincide'])->assertOk()->assertJsonPath('data.status', PaymentSubmission::REJECTED);
        $this->patchJson('/api/v1/treasury/payment-submissions/'.$observed->id.'/reject', ['reason' => 'La operación no corresponde'])->assertOk()->assertJsonPath('data.status', PaymentSubmission::REJECTED);
        $this->patchJson('/api/v1/treasury/payment-submissions/'.$terminal->id.'/reject', ['reason' => 'Repetido'])->assertConflict();
        $this->patchJson('/api/v1/treasury/payment-submissions/'.$terminal->id.'/observe', ['reason' => 'Repetido'])->assertConflict();
        $this->patchJson('/api/v1/treasury/payment-submissions/'.$terminal->id.'/reject', ['reason' => 'x'])->assertUnprocessable();
        $this->patchJson('/api/v1/treasury/payment-submissions/'.$terminal->id.'/reject', ['reason' => 'Motivo válido', 'status' => 'approved'])->assertUnprocessable();
        $this->patchJson('/api/v1/treasury/payment-submissions/'.$terminal->id.'/reject', ['reason' => '   '])->assertUnprocessable();
        $this->patchJson('/api/v1/treasury/payment-submissions/'.$terminal->id.'/reject', ['reason' => ['no válido']])->assertUnprocessable();
    }

    /** @dataProvider terminalStatuses */
    public function test_terminal_statuses_cannot_be_observed_or_rejected(string $status): void
    {
        $submission = $this->submission(['status' => $status]);
        Sanctum::actingAs($this->treasury());
        $this->patchJson('/api/v1/treasury/payment-submissions/'.$submission->id.'/observe', ['reason' => 'Motivo válido'])->assertConflict();
        $this->patchJson('/api/v1/treasury/payment-submissions/'.$submission->id.'/reject', ['reason' => 'Motivo válido'])->assertConflict();
        $this->assertSame(0, PaymentSubmissionHistory::count());
    }

    public static function terminalStatuses(): array
    {
        return [[PaymentSubmission::REJECTED], [PaymentSubmission::APPROVED], [PaymentSubmission::EXPIRED], [PaymentSubmission::CANCELED]];
    }

    public function test_customer_recovers_safe_observed_and_rejected_messages_without_internal_data(): void
    {
        $observed = $this->submission(['status' => PaymentSubmission::OBSERVED, 'decision_reason' => 'Verifique el número informado.']);
        Sanctum::actingAs($observed->submitter);
        $this->getJson('/api/v1/orders/'.$observed->order_id.'/payment-submission')
            ->assertOk()->assertJsonPath('data.status', PaymentSubmission::OBSERVED)
            ->assertJsonPath('data.message', 'Tesorería solicitó revisar los datos del pago.')
            ->assertJsonPath('data.reason', 'Verifique el número informado.')
            ->assertJsonMissingPath('data.idempotency_key');
        $rejected = $this->submission(['status' => PaymentSubmission::REJECTED, 'decision_reason' => 'La operación no corresponde al importe.']);
        Sanctum::actingAs($rejected->submitter);
        $this->getJson('/api/v1/orders/'.$rejected->order_id.'/payment-submission')
            ->assertOk()->assertJsonPath('data.message', 'La presentación del pago fue rechazada.')
            ->assertJsonPath('data.reason', 'La operación no corresponde al importe.');
    }

    public function test_failed_history_write_rolls_back_the_decision(): void
    {
        [$submission, $order, $reservation] = $this->submissionWithInventory();
        $before = [$order->reserved_until?->toIso8601String(), $reservation->expires_at?->toIso8601String()];
        $treasury = $this->treasury();
        PaymentSubmissionHistory::creating(fn () => throw new \RuntimeException('forced history failure'));
        try {
            app(\App\Services\TreasuryPaymentReviewService::class)->observe($submission->id, $treasury->id, 'Motivo válido');
            $this->fail('La transacción no se revirtió.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('forced history failure', $exception->getMessage());
        } finally {
            PaymentSubmissionHistory::flushEventListeners();
        }
        $this->assertSame(PaymentSubmission::PENDING_REVIEW, $submission->fresh()->status);
        $this->assertNull($submission->fresh()->reviewed_by);
        $this->assertNull($submission->fresh()->reviewed_at);
        $this->assertNull($submission->fresh()->decision_reason);
        $this->assertSame($before[0], $order->fresh()->reserved_until?->toIso8601String());
        $this->assertSame($before[1], $reservation->fresh()->expires_at?->toIso8601String());
        $this->assertSame(0, PaymentSubmissionHistory::count());
    }

    public function test_observe_synchronizes_all_active_reservations_without_shortening_or_financial_effects(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-24 10:00:00', config('app.timezone')));
        try {
            [$submission, $order, $first, $inventory] = $this->submissionWithInventory();
            $second = $this->additionalReservation($order, $first, now()->addMinutes(180));
            $first->update(['expires_at' => now()->addMinutes(30)]);
            $order->update(['reserved_until' => now()->addMinutes(60)]);
            config(['treasury.observation_correction_minutes' => 120]);
            $beforeInventory = $inventory->fresh()->only(['quantity', 'reserved_quantity']);
            $treasury = $this->treasury();

            app(\App\Services\TreasuryPaymentReviewService::class)->observe($submission->id, $treasury->id, 'Revisar comprobante');

            $expected = now()->addMinutes(180)->toIso8601String();
            $this->assertSame($expected, $order->fresh()->reserved_until?->toIso8601String());
            $this->assertSame($expected, $first->fresh()->expires_at?->toIso8601String());
            $this->assertSame($expected, $second->fresh()->expires_at?->toIso8601String());
            $history = PaymentSubmissionHistory::where('event', 'observed')->sole();
            $this->assertSame(PaymentSubmission::PENDING_REVIEW, $history->from_status);
            $this->assertSame(PaymentSubmission::OBSERVED, $history->to_status);
            $this->assertSame($treasury->id, $history->actor_id);
            $this->assertSame('Revisar comprobante', $history->reason);
            $this->assertSame(120, $history->safe_metadata['configured_minutes']);
            $this->assertSame($expected, $history->safe_metadata['new_expires_at']);
            $this->assertSame($beforeInventory, $inventory->fresh()->only(['quantity', 'reserved_quantity']));
            $this->assertSame(0, PaymentTransaction::count());
            $this->assertSame(0, InventoryMovement::count());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_observe_only_extends_active_reservations_and_rejects_when_none_are_valid(): void
    {
        [$submission, $order, $active] = $this->submissionWithInventory();
        $terminal = $this->additionalReservation($order, $active, now()->addHours(2), InventoryReservation::RELEASED);
        $terminalBefore = [$terminal->status, $terminal->expires_at?->toIso8601String()];
        app(\App\Services\TreasuryPaymentReviewService::class)->observe($submission->id, $this->treasury()->id, 'Motivo válido');
        $this->assertSame($terminalBefore, [$terminal->fresh()->status, $terminal->fresh()->expires_at?->toIso8601String()]);
        $this->assertSame(InventoryReservation::ACTIVE, $active->fresh()->status);

        [$invalidSubmission, $invalidOrder, $invalidReservation] = $this->submissionWithInventory();
        $invalidReservation->update(['status' => InventoryReservation::RELEASED]);
        $before = [$invalidSubmission->status, $invalidOrder->reserved_until?->toIso8601String(), $invalidReservation->expires_at?->toIso8601String(), PaymentSubmissionHistory::count()];
        try {
            app(\App\Services\TreasuryPaymentReviewService::class)->observe($invalidSubmission->id, $this->treasury()->id, 'Motivo válido');
            $this->fail('Una orden sin reserva activa y vigente fue observada.');
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $exception) {
            $this->assertSame(409, $exception->getResponse()->getStatusCode());
        }
        $this->assertSame($before[0], $invalidSubmission->fresh()->status);
        $this->assertSame($before[1], $invalidOrder->fresh()->reserved_until?->toIso8601String());
        $this->assertSame($before[2], $invalidReservation->fresh()->expires_at?->toIso8601String());
        $this->assertSame($before[3], PaymentSubmissionHistory::count());
    }

    public function test_treasury_history_resource_exposes_only_allowlisted_expiry_metadata(): void
    {
        $submission = $this->submission();
        foreach (['observed', 'corrected'] as $event) {
            $history = PaymentSubmissionHistory::create([
                'payment_submission_id' => $submission->id,
                'event' => $event,
                'from_status' => $event === 'observed' ? PaymentSubmission::PENDING_REVIEW : PaymentSubmission::OBSERVED,
                'to_status' => $event === 'observed' ? PaymentSubmission::OBSERVED : PaymentSubmission::PENDING_REVIEW,
                'safe_metadata' => [
                    'previous_expires_at' => '2026-09-24T10:00:00-05:00',
                    'new_expires_at' => '2026-09-24T12:00:00-05:00',
                    'configured_minutes' => 120,
                    'operation_number' => '001234567',
                    'normalized_operation_number' => '001234567',
                    'origin_phone_last4' => '0140',
                    'origin_bank' => 'Banco privado',
                    'duplicate_fingerprint' => 'secret',
                    'idempotency_key' => 'secret',
                    'receiving_account_snapshot' => ['id' => 1],
                    'qr_path' => 'private/qr.png',
                    'qr_disk' => 'treasury_qr',
                ],
                'occurred_at' => now(),
            ]);
            $data = (new TreasuryPaymentSubmissionHistoryResource($history))->resolve(Request::create('/api/v1/treasury/payment-submissions/'.$submission->id.'/history'));
            $this->assertSame('2026-09-24T10:00:00-05:00', $data['previous_expires_at']);
            $this->assertSame('2026-09-24T12:00:00-05:00', $data['new_expires_at']);
            foreach (['safe_metadata', 'configured_minutes', 'operation_number', 'normalized_operation_number', 'origin_phone_last4', 'origin_bank', 'duplicate_fingerprint', 'idempotency_key', 'receiving_account_snapshot', 'qr_path', 'qr_disk'] as $private) {
                $this->assertArrayNotHasKey($private, $data);
            }
        }
    }

    private function treasury(): User
    {
        return User::factory()->create(['role' => 'treasury', 'is_active' => true]);
    }

    private function submission(array $overrides = []): PaymentSubmission
    {
        $owner = User::factory()->create();
        $order = Order::create(['user_id' => $owner->id, 'status' => 'pending', 'total' => '20.00', 'shipping_info' => ['recipient_name' => 'Cliente'], 'payment_method' => 'transferencia', 'payment_status' => 'pending', 'reserved_until' => now()->addHour(), 'delivery_type' => 'delivery', 'tracking_status' => 'pending']);

        return PaymentSubmission::create(array_merge([
            'order_id' => $order->id, 'channel' => 'yape', 'status' => PaymentSubmission::PENDING_REVIEW,
            'expected_amount' => '20.00', 'currency' => 'PEN', 'operation_number' => '000123456', 'normalized_operation_number' => '000123456',
            'duplicate_fingerprint' => hash('sha256', 'fp-'.$order->id), 'declared_paid_at' => now()->subMinutes(5),
            'origin_phone_last4' => '1234', 'receiving_account_snapshot' => ['id' => 1, 'code' => 'YAPE-1', 'display_name' => 'Yape principal'],
            'submitted_by' => $owner->id, 'submitted_at' => now()->subMinutes(5), 'idempotency_key' => 'key-'.$order->id,
        ], $overrides));
    }

    private function submissionWithInventory(): array
    {
        $submission = $this->submission();
        $order = $submission->order;
        $suffix = (string) (Order::count() + 1);
        $branch = Branch::create(['code' => 'B'.$suffix, 'name' => 'Sede', 'address' => 'Dirección', 'is_active' => true]);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'code' => 'W'.$suffix, 'name' => 'Almacén', 'is_active' => true, 'is_default' => false]);
        $product = Product::create(['name' => 'Producto '.$suffix, 'slug' => 'producto-'.$suffix, 'sku' => 'SKU-'.$suffix, 'price' => '20.00', 'sale_price' => '20.00', 'is_active' => true]);
        $inventory = WarehouseInventory::create(['warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'quantity' => 2, 'reserved_quantity' => 1]);
        $item = $order->items()->create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'quantity' => 1, 'price' => '20.00', 'subtotal' => '20.00']);
        $reservation = InventoryReservation::create(['order_id' => $order->id, 'order_item_id' => $item->id, 'product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'quantity' => 1, 'status' => InventoryReservation::ACTIVE, 'expires_at' => now()->addHour(), 'idempotency_key' => 'reservation-'.$order->id]);

        return [$submission, $order, $reservation, $inventory];
    }

    private function additionalReservation(Order $order, InventoryReservation $base, Carbon $expiresAt, string $status = InventoryReservation::ACTIVE): InventoryReservation
    {
        $warehouse = $base->warehouse;
        $suffix = (string) (InventoryReservation::count() + 10);
        $product = Product::create(['name' => 'Producto '.$suffix, 'slug' => 'producto-'.$suffix, 'sku' => 'SKU-'.$suffix, 'price' => '20.00', 'sale_price' => '20.00', 'is_active' => true]);
        WarehouseInventory::create(['warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'quantity' => 2, 'reserved_quantity' => 1]);
        $item = $order->items()->create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'quantity' => 1, 'price' => '20.00', 'subtotal' => '20.00']);

        return InventoryReservation::create(['order_id' => $order->id, 'order_item_id' => $item->id, 'product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'quantity' => 1, 'status' => $status, 'expires_at' => $expiresAt, 'idempotency_key' => 'reservation-extra-'.$order->id.'-'.$suffix]);
    }
}
