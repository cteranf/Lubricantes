<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\InventoryMovement;
use App\Models\InventoryReservation;
use App\Models\Order;
use App\Models\PaymentReceivingAccount;
use App\Models\PaymentSubmission;
use App\Models\PaymentSubmissionHistory;
use App\Models\PaymentTransaction;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseInventory;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CustomerPaymentSubmissionTest extends TestCase
{
    use RefreshDatabase;

    /** @dataProvider channels */
    public function test_customer_can_submit_each_supported_transfer_channel(string $channel): void
    {
        [$owner, $order] = $this->order('30.50');
        $account = $this->account($channel, true, true);
        Sanctum::actingAs($owner);

        $response = $this->postSubmission($order, $this->payload($channel, $account->id), 'key-'.$channel)
            ->assertCreated()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Pragma', 'no-cache');

        $response->assertJsonPath('data.status', PaymentSubmission::PENDING_REVIEW)
            ->assertJsonPath('data.channel', $channel)
            ->assertJsonPath('data.expected_amount', '30.50')
            ->assertJsonPath('data.currency', 'PEN')
            ->assertJsonPath('data.operation_number_masked', '•••••4567')
            ->assertJsonMissingPath('data.operation_number')
            ->assertJsonMissingPath('data.duplicate_fingerprint')
            ->assertJsonMissingPath('data.idempotency_key')
            ->assertJsonMissingPath('data.origin_phone_last4')
            ->assertJsonMissingPath('data.receiving_account_snapshot');
        $submission = PaymentSubmission::firstOrFail();
        $this->assertSame('001234567', $submission->operation_number);
        $this->assertSame('001234567', $submission->normalized_operation_number);
        $this->assertSame($order->id, $submission->order_id);
        $this->assertSame($owner->id, $submission->submitted_by);
        $this->assertSame($channel === 'bank_transfer' ? 'Banco de origen' : null, $submission->origin_bank);
        $this->assertSame($channel === 'bank_transfer' ? null : '0140', $submission->origin_phone_last4);
        $this->assertSame(1, PaymentSubmissionHistory::count());
    }

    public static function channels(): array
    {
        return [['yape'], ['plin'], ['bank_transfer']];
    }

    public function test_authentication_ownership_and_eligibility_are_enforced(): void
    {
        [$owner, $order] = $this->order();
        $account = $this->account('yape', true, true);
        $payload = $this->payload('yape', $account->id);
        $this->postJson('/api/v1/orders/'.$order->id.'/payment-submission', $payload)->assertUnauthorized();
        Sanctum::actingAs(User::factory()->create(['is_active' => false]));
        $this->postSubmission($order, $payload, 'inactive')->assertForbidden()->assertJsonPath('code', 'account_inactive');
        Sanctum::actingAs(User::factory()->create(['role' => 'customer']));
        $this->postSubmission($order, $payload, 'other')->assertNotFound();
        Sanctum::actingAs($owner);
        $this->postSubmission($order, array_merge($payload, ['payment_status' => 'approved']), 'hostile')->assertUnprocessable();
        $this->postSubmission($order, $payload, 'missing')->assertCreated();
        $this->assertSame(1, PaymentSubmission::count());
    }

    /** @dataProvider ineligibleOrders */
    public function test_ineligible_orders_and_reservations_cannot_create_submissions(string $field, mixed $value): void
    {
        [$owner, $order] = $this->order();
        $account = $this->account('yape', true, true);
        if ($field === 'no_active_reservation') {
            InventoryReservation::whereOrderId($order->id)->update(['status' => InventoryReservation::RELEASED]);
        } elseif (str_starts_with($field, 'reservation.')) {
            InventoryReservation::whereOrderId($order->id)->update([substr($field, 12) => $value === 'past' ? now()->subMinute() : $value]);
        } else {
            $order->update([$field => $value === 'past' ? now()->subMinute() : $value]);
        }
        Sanctum::actingAs($owner);
        $this->postSubmission($order, $this->payload('yape', $account->id), 'ineligible-'.$field)->assertUnprocessable();
        $this->assertSame(0, PaymentSubmission::count());
        $this->assertSame(0, PaymentSubmissionHistory::count());
    }

    public static function ineligibleOrders(): array
    {
        return [
            'other payment method' => ['payment_method', 'card'],
            'approved payment' => ['payment_status', 'approved'],
            'rejected payment' => ['payment_status', 'rejected'],
            'canceled order' => ['status', 'canceled'],
            'delivered order' => ['status', 'delivered'],
            'picked up order' => ['tracking_status', 'picked_up'],
            'expired order reservation' => ['reserved_until', 'past'],
            'released reservation' => ['reservation.status', InventoryReservation::RELEASED],
            'consumed reservation' => ['reservation.status', InventoryReservation::CONSUMED],
            'expired active reservation' => ['reservation.expires_at', 'past'],
            'no active reservation' => ['no_active_reservation', null],
        ];
    }

    public function test_receiving_account_and_conditional_fields_are_revalidated_server_side(): void
    {
        [$owner, $order] = $this->order();
        Sanctum::actingAs($owner);
        $secondary = $this->account('yape', true, false);
        $this->postSubmission($order, $this->payload('yape', $secondary->id), 'secondary')->assertUnprocessable();

        PaymentReceivingAccount::query()->delete();
        $inactive = $this->account('yape', false, false);
        $this->postSubmission($order, $this->payload('yape', $inactive->id), 'inactive-account')->assertUnprocessable();

        PaymentReceivingAccount::query()->delete();
        $usd = $this->account('yape', true, true, 'USD');
        $this->postSubmission($order, $this->payload('yape', $usd->id), 'usd-account')->assertUnprocessable();

        PaymentReceivingAccount::query()->delete();
        $bank = $this->account('bank_transfer', true, true);
        $this->postSubmission($order, $this->payload('yape', $bank->id), 'mismatch')->assertUnprocessable();
        $this->postSubmission($order, array_merge($this->payload('bank_transfer', $bank->id), ['origin_bank' => null]), 'bank-required')->assertUnprocessable();
        $this->postSubmission($order, array_merge($this->payload('yape', $bank->id), ['operation_number' => 1234567]), 'numeric-operation')->assertUnprocessable();
        $this->assertSame(0, PaymentSubmission::count());
    }

    public function test_extension_is_synchronized_without_shortening_and_creation_leaves_inventory_untouched(): void
    {
        [$owner, $order, $reservation, $inventory] = $this->order('14.20', true);
        $account = $this->account('yape', true, true);
        $later = now()->addMinutes(config('treasury.reservation_extension_minutes') + 30);
        $order->update(['reserved_until' => $later]);
        $reservation->update(['expires_at' => $later]);
        $inventoryBefore = $inventory->fresh()->only(['quantity', 'reserved_quantity']);
        Sanctum::actingAs($owner);
        $this->postSubmission($order, $this->payload('yape', $account->id), 'extend')->assertCreated();

        $this->assertSame($later->format('Y-m-d H:i:s'), $order->fresh()->reserved_until->format('Y-m-d H:i:s'));
        $this->assertSame($later->format('Y-m-d H:i:s'), $reservation->fresh()->expires_at->format('Y-m-d H:i:s'));
        $this->assertSame($inventoryBefore, $inventory->fresh()->only(['quantity', 'reserved_quantity']));
        $this->assertSame('pending', $order->fresh()->payment_status);
        $this->assertNull($order->fresh()->paid_at);
        $this->assertSame(0, InventoryMovement::count());
        $this->assertSame(0, PaymentTransaction::count());
        $history = PaymentSubmissionHistory::firstOrFail();
        $this->assertSame($owner->id, $history->safe_metadata['submitted_by']);
        $this->assertArrayNotHasKey('operation_number', $history->safe_metadata);
        $this->assertArrayNotHasKey('idempotency_key', $history->safe_metadata);
    }

    public function test_idempotency_conflicts_and_duplicate_fingerprints_are_controlled(): void
    {
        [$owner, $order] = $this->order();
        $account = $this->account('yape', true, true);
        Sanctum::actingAs($owner);
        $payload = $this->payload('yape', $account->id);
        $this->postSubmission($order, $payload, 'same-key')->assertCreated();
        $stored = PaymentSubmission::firstOrFail();
        $this->assertSame($order->id, $stored->order_id);
        $this->assertSame($owner->id, $stored->submitted_by);
        $this->assertSame('yape', $stored->channel);
        $this->assertSame('001234567', $stored->normalized_operation_number);
        $this->assertSame('0140', $stored->origin_phone_last4);
        $this->assertSame($account->id, data_get($stored->receiving_account_snapshot, 'id'));
        $this->assertSame(now()->parse($payload['paid_at'])->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s'), $stored->declared_paid_at->format('Y-m-d H:i:s'));
        $expiryBeforeRetry = $order->fresh()->reserved_until->format('Y-m-d H:i:s');
        $reservationBeforeRetry = InventoryReservation::whereOrderId($order->id)->firstOrFail()->expires_at->format('Y-m-d H:i:s');
        $this->postSubmission($order, array_merge($payload, ['operation_number' => '00-123 4567']), 'same-key')
            ->assertOk()
            ->assertJsonPath('data.id', $stored->id);
        $this->assertSame(1, PaymentSubmission::count());
        $this->assertSame(1, PaymentSubmissionHistory::count());
        $this->assertSame($expiryBeforeRetry, $order->fresh()->reserved_until->format('Y-m-d H:i:s'));
        $this->assertSame($reservationBeforeRetry, InventoryReservation::whereOrderId($order->id)->firstOrFail()->expires_at->format('Y-m-d H:i:s'));
        $this->postSubmission($order, array_merge($payload, ['operation_number' => '009999']), 'same-key')->assertConflict();
        $this->postSubmission($order, $payload, 'other-key')->assertConflict();

        [$otherOwner, $otherOrder] = $this->order();
        Sanctum::actingAs($otherOwner);
        $this->postSubmission($otherOrder, $payload, 'same-key')->assertConflict();
        $this->postSubmission($otherOrder, $payload, 'other-order')->assertConflict();
        $this->assertSame(1, PaymentSubmission::count());
    }

    public function test_failure_while_creating_history_rolls_back_submission_and_reservation_extension(): void
    {
        [$owner, $order, $reservation, $inventory] = $this->order('18.00', true);
        $account = $this->account('yape', true, true);
        $before = [
            'order_expiry' => $order->fresh()->reserved_until->format('Y-m-d H:i:s'),
            'reservation_expiry' => $reservation->fresh()->expires_at->format('Y-m-d H:i:s'),
            'inventory' => $inventory->fresh()->only(['quantity', 'reserved_quantity']),
        ];
        PaymentSubmissionHistory::creating(fn () => throw new \RuntimeException('forced history failure'));

        try {
            app(\App\Services\CustomerPaymentSubmissionService::class)->submit(
                $order,
                $owner->id,
                $this->payload('yape', $account->id),
                'history-failure',
            );
            $this->fail('La falla inducida no interrumpió la transacción.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('forced history failure', $exception->getMessage());
        } finally {
            PaymentSubmissionHistory::flushEventListeners();
        }

        $this->assertSame(0, PaymentSubmission::count());
        $this->assertSame(0, PaymentSubmissionHistory::count());
        $this->assertSame($before['order_expiry'], $order->fresh()->reserved_until->format('Y-m-d H:i:s'));
        $this->assertSame($before['reservation_expiry'], $reservation->fresh()->expires_at->format('Y-m-d H:i:s'));
        $this->assertSame($before['inventory'], $inventory->fresh()->only(['quantity', 'reserved_quantity']));
    }

    public function test_creation_rethrows_non_unique_database_errors_without_converting_them_to_conflicts(): void
    {
        [$owner, $order, $reservation] = $this->order('18.00', true);
        $account = $this->account('yape', true, true);
        $beforeOrderExpiry = $order->fresh()->reserved_until->format('Y-m-d H:i:s');
        $beforeReservationExpiry = $reservation->fresh()->expires_at->format('Y-m-d H:i:s');
        $previous = new \PDOException('FOREIGN KEY constraint failed', 19);
        $previous->errorInfo = ['HY000', 19, 'FOREIGN KEY constraint failed'];
        $failure = new \Illuminate\Database\QueryException('insert into payment_submission_histories values (?)', [], $previous);
        PaymentSubmissionHistory::creating(fn () => throw $failure);

        try {
            app(\App\Services\CustomerPaymentSubmissionService::class)->submit(
                $order,
                $owner->id,
                $this->payload('yape', $account->id),
                'non-unique-db-error',
            );
            $this->fail('El error de integridad no UNIQUE fue convertido indebidamente en conflicto.');
        } catch (\Illuminate\Database\QueryException $exception) {
            $this->assertSame($failure, $exception);
        } finally {
            PaymentSubmissionHistory::flushEventListeners();
        }

        $this->assertSame(0, PaymentSubmission::count());
        $this->assertSame(0, PaymentSubmissionHistory::count());
        $this->assertSame($beforeOrderExpiry, $order->fresh()->reserved_until->format('Y-m-d H:i:s'));
        $this->assertSame($beforeReservationExpiry, $reservation->fresh()->expires_at->format('Y-m-d H:i:s'));
    }

    public function test_declared_payment_date_allows_the_configured_future_boundary_and_normalizes_offsets(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-09 10:00:00', config('app.timezone')));
        config(['treasury.payment_submission_future_tolerance_minutes' => 10]);
        try {
            [$owner, $order] = $this->order();
            $account = $this->account('yape', true, true);
            Sanctum::actingAs($owner);
            $boundary = now()->addMinutes(10);
            $this->postSubmission($order, array_merge($this->payload('yape', $account->id), ['paid_at' => $boundary->toIso8601String()]), 'boundary')->assertCreated();
            $this->assertSame($boundary->format('Y-m-d H:i:s'), PaymentSubmission::firstOrFail()->declared_paid_at->format('Y-m-d H:i:s'));

            [$otherOwner, $otherOrder] = $this->order();
            Sanctum::actingAs($otherOwner);
            $this->postSubmission($otherOrder, array_merge($this->payload('yape', $account->id), ['paid_at' => now()->addMinutes(10)->addSecond()->utc()->toIso8601String()]), 'over-boundary')->assertUnprocessable();
            $this->assertNull($order->fresh()->paid_at);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_owner_can_recover_a_safe_existing_submission_without_mutations(): void
    {
        [$owner, $order, $reservation, $inventory] = $this->order('22.00', true);
        $account = $this->account('yape', true, true);
        Sanctum::actingAs($owner);
        $this->postSubmission($order, $this->payload('yape', $account->id), 'recover')->assertCreated();
        $submission = PaymentSubmission::firstOrFail();
        $before = [$order->fresh()->updated_at->format('c'), $reservation->fresh()->updated_at->format('c'), $inventory->fresh()->only(['quantity', 'reserved_quantity']), PaymentSubmissionHistory::count()];
        $reservation->update(['expires_at' => now()->subMinute()]);
        $account->update(['is_active' => false]);
        $response = $this->getJson('/api/v1/orders/'.$order->id.'/payment-submission')->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Pragma', 'no-cache');
        $response->assertJsonPath('data.id', $submission->id)->assertJsonMissingPath('data.operation_number')->assertJsonMissingPath('data.normalized_operation_number')->assertJsonMissingPath('data.origin_phone_last4')->assertJsonMissingPath('data.duplicate_fingerprint')->assertJsonMissingPath('data.idempotency_key')->assertJsonMissingPath('data.receiving_account_snapshot');
        $this->assertSame($before[2], $inventory->fresh()->only(['quantity', 'reserved_quantity']));
        $this->assertSame($before[3], PaymentSubmissionHistory::count());

        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/v1/orders/'.$order->id.'/payment-submission')->assertNotFound()->assertJsonMissingPath('code');
        Sanctum::actingAs($owner);
        $this->getJson('/api/v1/orders/999999/payment-submission')->assertNotFound();
        [$otherOwner, $otherOrder] = $this->order();
        Sanctum::actingAs($otherOwner);
        $missing = $this->getJson('/api/v1/orders/'.$otherOrder->id.'/payment-submission')
            ->assertNotFound()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Pragma', 'no-cache')
            ->assertJsonPath('message', 'Aún no se ha registrado una presentación de pago para este pedido.')
            ->assertJsonPath('code', 'payment_submission_not_found');
        foreach (['exception', 'file', 'line', 'trace', 'sql', 'App\\Models\\PaymentSubmission'] as $sensitive) {
            $this->assertStringNotContainsString($sensitive, $missing->getContent());
        }
    }

    public function test_observed_submission_exposes_its_safe_reason_and_current_reservation_expiry(): void
    {
        [$owner, $order, $reservation, $inventory] = $this->order('22.00', true);
        $account = $this->account('yape', true, true);
        $initialExpiry = now()->addMinute();
        $order->update(['reserved_until' => $initialExpiry]);
        $reservation->update(['expires_at' => $initialExpiry]);

        Sanctum::actingAs($owner);
        $this->postSubmission($order, $this->payload('yape', $account->id), 'observed-current-expiry')->assertCreated();
        $submission = PaymentSubmission::firstOrFail();
        Sanctum::actingAs(User::factory()->create(['role' => 'treasury', 'is_active' => true]));
        $this->patchJson('/api/v1/treasury/payment-submissions/'.$submission->id.'/observe', ['reason' => 'Prueba'])->assertOk();

        $currentOrder = $order->fresh();
        $currentReservation = $reservation->fresh();
        $this->assertTrue($currentOrder->reserved_until->greaterThan($initialExpiry));
        $this->assertSame($currentOrder->reserved_until->format('c'), $currentReservation->expires_at->format('c'));
        $beforeRead = [
            'order_updated_at' => $currentOrder->updated_at->format('c'),
            'reservation_updated_at' => $currentReservation->updated_at->format('c'),
            'inventory' => $inventory->fresh()->only(['quantity', 'reserved_quantity']),
        ];

        Sanctum::actingAs($owner);
        $response = $this->getJson('/api/v1/orders/'.$order->id.'/payment-submission')->assertOk();
        $response
            ->assertJsonPath('data.status', PaymentSubmission::OBSERVED)
            ->assertJsonPath('data.reason', 'Prueba')
            ->assertJsonPath('data.reservation_expires_at', $currentOrder->reserved_until->toIso8601String())
            ->assertJsonMissingPath('data.metadata')
            ->assertJsonMissingPath('data.receiving_account_snapshot')
            ->assertJsonMissingPath('data.duplicate_fingerprint')
            ->assertJsonMissingPath('data.idempotency_key');

        $this->assertSame($beforeRead['order_updated_at'], $order->fresh()->updated_at->format('c'));
        $this->assertSame($beforeRead['reservation_updated_at'], $reservation->fresh()->updated_at->format('c'));
        $this->assertSame($beforeRead['inventory'], $inventory->fresh()->only(['quantity', 'reserved_quantity']));
    }

    public function test_approved_submission_exposes_only_the_safe_customer_confirmation(): void
    {
        [$owner, $order] = $this->order('22.00');
        $account = $this->account('yape', true, true);
        Sanctum::actingAs($owner);
        $this->postSubmission($order, $this->payload('yape', $account->id), 'approved-customer-resource')->assertCreated();
        $submission = PaymentSubmission::firstOrFail();

        $treasury = User::factory()->create(['role' => 'treasury', 'is_active' => true]);
        $submission->update([
            'status' => PaymentSubmission::APPROVED,
            'reviewed_by' => $treasury->id,
            'reviewed_at' => now(),
        ]);

        Sanctum::actingAs($owner);
        $response = $this->getJson('/api/v1/orders/'.$order->id.'/payment-submission')->assertOk();
        $response
            ->assertJsonPath('data.status', PaymentSubmission::APPROVED)
            ->assertJsonPath('data.message', 'Pago validado por Tesorería.')
            ->assertJsonPath('data.validated_at', PaymentSubmission::firstOrFail()->reviewed_at->toIso8601String())
            ->assertJsonMissingPath('data.transaction')
            ->assertJsonMissingPath('data.payment_transaction')
            ->assertJsonMissingPath('data.idempotency_key')
            ->assertJsonMissingPath('data.approved_scope_key')
            ->assertJsonMissingPath('data.receiving_account_snapshot');
    }

    public function test_operation_number_mask_is_safe_for_short_long_and_normalized_values(): void
    {
        foreach ([
            '1' => '•',
            '12' => '••',
            '123' => '•23',
            '1111' => '••11',
            '12345' => '•••45',
            '123456' => '••••56',
            '1234567' => '•••4567',
            '001234567' => '•••••4567',
        ] as $operation => $masked) {
            $submission = new PaymentSubmission(['operation_number' => $operation]);
            $this->assertSame($masked, $submission->maskedOperationNumber());
            $this->assertNotSame($operation, $submission->maskedOperationNumber());
        }

        $normalized = app(\App\Services\TreasuryPaymentService::class)->normalizeOperationNumber('00-123 4567');
        $submission = new PaymentSubmission(['operation_number' => $normalized]);
        $this->assertSame('001234567', $normalized);
        $this->assertSame('•••••4567', $submission->maskedOperationNumber());
        $this->assertStringNotContainsString('1111', (new PaymentSubmission(['operation_number' => '1111']))->maskedOperationNumber());
    }

    private function postSubmission(Order $order, array $payload, string $key)
    {
        return $this->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/orders/'.$order->id.'/payment-submission', $payload);
    }

    private function payload(string $channel, int $accountId): array
    {
        return array_filter([
            'channel' => $channel,
            'receiving_account_id' => $accountId,
            'operation_number' => '001234567',
            'paid_at' => now()->subMinute()->toIso8601String(),
            'origin_phone_last_four' => in_array($channel, ['yape', 'plin'], true) ? '0140' : null,
            'origin_bank' => $channel === 'bank_transfer' ? 'Banco de origen' : null,
        ], fn ($value) => $value !== null);
    }

    private function order(string $total = '10.00', bool $returnRelated = false): array
    {
        $suffix = (string) (Order::count() + 1);
        $owner = User::factory()->create();
        $branch = Branch::create(['code' => 'B'.$suffix, 'name' => 'B', 'address' => 'Dirección', 'is_active' => true]);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'code' => 'W'.$suffix, 'name' => 'W', 'is_active' => true, 'is_default' => false]);
        $product = Product::create(['name' => 'P'.$suffix, 'slug' => 'p-'.$suffix, 'sku' => 'P'.$suffix, 'price' => $total, 'sale_price' => $total, 'is_active' => true]);
        $inventory = WarehouseInventory::create(['warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'quantity' => 2, 'reserved_quantity' => 1]);
        $order = Order::create(['user_id' => $owner->id, 'status' => 'pending', 'total' => $total, 'shipping_info' => ['recipient_name' => 'Cliente'], 'payment_method' => 'transferencia', 'payment_status' => 'pending', 'reserved_until' => now()->addHour(), 'delivery_type' => 'delivery', 'tracking_status' => 'pending']);
        $item = $order->items()->create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'quantity' => 1, 'price' => $total, 'subtotal' => $total]);
        $reservation = InventoryReservation::create(['order_id' => $order->id, 'order_item_id' => $item->id, 'product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'quantity' => 1, 'status' => InventoryReservation::ACTIVE, 'expires_at' => now()->addHour(), 'idempotency_key' => 'r-'.$order->id]);

        return $returnRelated ? [$owner, $order, $reservation, $inventory] : [$owner, $order];
    }

    private function account(string $channel, bool $active, bool $default, string $currency = 'PEN'): PaymentReceivingAccount
    {
        return PaymentReceivingAccount::create([
            'code' => strtoupper($channel).'-'.(PaymentReceivingAccount::count() + 1),
            'channel' => $channel,
            'display_name' => 'Cuenta '.$channel,
            'holder_name' => 'Titular',
            'currency' => $currency,
            'phone' => in_array($channel, ['yape', 'plin'], true) ? '999999999' : null,
            'bank_name' => $channel === 'bank_transfer' ? 'Banco receptor' : null,
            'account_number' => $channel === 'bank_transfer' ? '123456' : null,
            'qr_path' => in_array($channel, ['yape', 'plin'], true) ? 'private/'.$channel.'.png' : null,
            'qr_disk' => in_array($channel, ['yape', 'plin'], true) ? 'treasury_qr' : null,
            'is_active' => $active,
            'is_default' => $default,
        ]);
    }
}
