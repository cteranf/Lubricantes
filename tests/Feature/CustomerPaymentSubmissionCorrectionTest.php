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
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PDOException;
use Tests\TestCase;

class CustomerPaymentSubmissionCorrectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_observed_owner_can_correct_once_and_retry_identically_without_side_effects(): void
    {
        [$owner, $order, $submission, $reservation, $inventory] = $this->fixture();
        $account = $this->account('yape');
        $before = [$order->reserved_until->format('c'), $inventory->only(['quantity', 'reserved_quantity'])];
        Sanctum::actingAs($owner);
        $payload = ['channel' => 'yape', 'receiving_account_id' => $account->id, 'operation_number' => '001234568', 'paid_at' => now()->subMinute()->toIso8601String(), 'origin_phone_last_four' => '0140'];
        $this->withHeader('Idempotency-Key', 'correction-key')->putJson('/api/v1/orders/'.$order->id.'/payment-submission/correction', $payload)
            ->assertOk()->assertJsonPath('data.status', PaymentSubmission::PENDING_REVIEW)->assertJsonPath('data.operation_number_masked', '•••••4568');
        $submission->refresh();
        $this->assertSame('001234568', $submission->operation_number);
        $this->assertNull($submission->decision_reason);
        $this->assertNull($submission->reviewed_by);
        $this->assertNull($submission->reviewed_at);
        $this->assertSame(1, PaymentSubmissionHistory::where('event', 'corrected')->count());
        $this->assertGreaterThanOrEqual($before[0], $order->fresh()->reserved_until->format('c'));
        $this->assertSame($order->fresh()->reserved_until->format('c'), $reservation->fresh()->expires_at->format('c'));
        $this->assertSame($before[1], $inventory->fresh()->only(['quantity', 'reserved_quantity']));
        $this->assertSame(0, PaymentTransaction::count());
        $this->assertSame(0, InventoryMovement::count());
        $this->withHeader('Idempotency-Key', 'correction-key')->putJson('/api/v1/orders/'.$order->id.'/payment-submission/correction', $payload)->assertOk();
        $this->assertSame(1, PaymentSubmissionHistory::where('event', 'corrected')->count());
        $this->withHeader('Idempotency-Key', 'different')->putJson('/api/v1/orders/'.$order->id.'/payment-submission/correction', $payload)->assertConflict();
    }

    public function test_correction_rejects_idor_ineligible_status_and_hostile_payload(): void
    {
        [$owner, $order, $submission] = $this->fixture();
        $account = $this->account('yape');
        $payload = ['channel' => 'yape', 'receiving_account_id' => $account->id, 'operation_number' => '001234568', 'paid_at' => now()->subMinute()->toIso8601String(), 'origin_phone_last_four' => '0140'];
        Sanctum::actingAs(User::factory()->create());
        $this->withHeader('Idempotency-Key', 'other')->putJson('/api/v1/orders/'.$order->id.'/payment-submission/correction', $payload)->assertNotFound();
        Sanctum::actingAs($owner);
        $this->withHeader('Idempotency-Key', 'hostile')->putJson('/api/v1/orders/'.$order->id.'/payment-submission/correction', array_merge($payload, ['status' => 'approved']))->assertUnprocessable();
        $submission->update(['status' => PaymentSubmission::REJECTED]);
        $this->withHeader('Idempotency-Key', 'terminal')->putJson('/api/v1/orders/'.$order->id.'/payment-submission/correction', $payload)->assertConflict();
    }

    public function test_plin_and_bank_corrections_clear_incompatible_origin_fields(): void
    {
        [$owner, $order] = $this->fixture();
        Sanctum::actingAs($owner);
        $plin = $this->account('plin');
        $this->withHeader('Idempotency-Key', 'plin-key')->putJson('/api/v1/orders/'.$order->id.'/payment-submission/correction', ['channel' => 'plin', 'receiving_account_id' => $plin->id, 'operation_number' => '0000001234', 'paid_at' => now()->subMinute()->toIso8601String(), 'origin_phone_last_four' => '9999', 'origin_bank' => 'No debe persistir'])->assertOk();
        $stored = PaymentSubmission::where('order_id', $order->id)->firstOrFail();
        $this->assertSame('0000001234', $stored->operation_number);
        $this->assertSame('9999', $stored->origin_phone_last4);
        $this->assertNull($stored->origin_bank);

        [$bankOwner, $bankOrder] = $this->fixture();
        Sanctum::actingAs($bankOwner);
        $bank = $this->account('bank_transfer');
        $this->withHeader('Idempotency-Key', 'bank-key')->putJson('/api/v1/orders/'.$bankOrder->id.'/payment-submission/correction', ['channel' => 'bank_transfer', 'receiving_account_id' => $bank->id, 'operation_number' => '0000005678', 'paid_at' => now()->subMinute()->toIso8601String(), 'origin_bank' => 'Banco de origen', 'origin_phone_last_four' => '9999'])->assertOk();
        $stored = PaymentSubmission::where('order_id', $bankOrder->id)->firstOrFail();
        $this->assertSame('Banco de origen', $stored->origin_bank);
        $this->assertNull($stored->origin_phone_last4);
    }

    public function test_authorization_missing_account_and_missing_submission_are_controlled_and_read_only(): void
    {
        [$owner, $order, $submission, $reservation] = $this->fixture();
        $account = $this->account('yape');
        $payload = ['channel' => 'yape', 'receiving_account_id' => $account->id, 'operation_number' => '001234568', 'paid_at' => now()->subMinute()->toIso8601String(), 'origin_phone_last_four' => '0140'];
        $url = '/api/v1/orders/'.$order->id.'/payment-submission/correction';
        $this->putJson($url, $payload)->assertUnauthorized();
        Sanctum::actingAs(User::factory()->create(['is_active' => false]));
        $this->withHeader('Idempotency-Key', 'inactive')->putJson($url, $payload)->assertForbidden()->assertJsonPath('code', 'account_inactive');
        foreach (['customer', 'admin', 'treasury'] as $role) {
            Sanctum::actingAs(User::factory()->create(['role' => $role]));
            $this->withHeader('Idempotency-Key', 'other-'.$role)->putJson($url, $payload)->assertNotFound();
        }
        Sanctum::actingAs($owner);
        $this->withHeader('Idempotency-Key', 'missing-account')->putJson($url, array_merge($payload, ['receiving_account_id' => 99999]))->assertUnprocessable();
        $noSubmission = Order::create(['user_id' => $owner->id, 'status' => 'pending', 'total' => '20.00', 'shipping_info' => ['recipient_name' => 'C'], 'payment_method' => 'transferencia', 'payment_status' => 'pending', 'reserved_until' => now()->addHour(), 'delivery_type' => 'delivery', 'tracking_status' => 'pending']);
        $response = $this->withHeader('Idempotency-Key', 'missing-submission')->putJson('/api/v1/orders/'.$noSubmission->id.'/payment-submission/correction', $payload)->assertNotFound();
        foreach (['exception', 'trace', 'file', 'line', 'sql', 'ModelNotFound'] as $sensitive) {
            $this->assertStringNotContainsString(strtolower($sensitive), strtolower($response->getContent()));
        }
        $this->assertSame(PaymentSubmission::OBSERVED, $submission->fresh()->status);
        $this->assertSame($reservation->expires_at->format('c'), $order->fresh()->reserved_until->format('c'));
    }

    public function test_dates_operations_and_hostile_financial_fields_are_validated(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-23 10:00:00', 'America/Lima'));
        try {
            [$owner,$order] = $this->fixture();
            $account = $this->account('yape');
            Sanctum::actingAs($owner);
            $url = '/api/v1/orders/'.$order->id.'/payment-submission/correction';
            $base = ['channel' => 'yape', 'receiving_account_id' => $account->id, 'operation_number' => '00001234', 'paid_at' => now()->addMinutes(config('treasury.payment_submission_future_tolerance_minutes'))->toIso8601String(), 'origin_phone_last_four' => '0140'];
            $this->withHeader('Idempotency-Key', 'boundary')->putJson($url, $base)->assertOk();
            $s = PaymentSubmission::where('order_id', $order->id)->firstOrFail();
            $this->assertSame('00001234', $s->operation_number);
            $this->assertSame('PEN', $s->currency);
            $this->assertNull($order->fresh()->paid_at);
            [$owner,$order] = $this->fixture();
            Sanctum::actingAs($owner);
            $this->withHeader('Idempotency-Key', 'future')->putJson('/api/v1/orders/'.$order->id.'/payment-submission/correction', array_merge($base, ['paid_at' => now()->addMinutes(config('treasury.payment_submission_future_tolerance_minutes'))->addSecond()->toIso8601String()]))->assertUnprocessable();
            $this->withHeader('Idempotency-Key', 'hostile')->putJson('/api/v1/orders/'.$order->id.'/payment-submission/correction', array_merge($base, ['amount' => '1.00', 'currency' => 'USD', 'expected_amount' => '1.00', 'payment_status' => 'approved', 'order_id' => 99, 'user_id' => 99, 'status' => 'approved', 'duplicate_fingerprint' => 'x', 'idempotency_key' => 'x', 'receiving_account_snapshot' => [], 'reviewed_by' => 99, 'reviewed_at' => now()->toIso8601String(), 'decision_reason' => 'x', 'submitted_at' => now()->toIso8601String(), 'metadata' => [], 'qr_path' => 'x']))->assertUnprocessable();
        } finally {
            Carbon::setTestNow();
        }
    }

    /** @dataProvider invalidAccountVariants */
    public function test_invalid_receiving_accounts_leave_the_observed_submission_untouched(array $overrides): void
    {
        [$owner, $order, $submission, $reservation] = $this->fixture();
        $account = $this->account('yape', $overrides);
        Sanctum::actingAs($owner);
        $before = [[$submission->status, $submission->decision_reason, $submission->reviewed_by, $submission->reviewed_at?->format('c'), $submission->operation_number], $order->reserved_until->format('c'), $reservation->expires_at->format('c'), PaymentSubmissionHistory::count()];
        $response = $this->withHeader('Idempotency-Key', 'invalid-account')->putJson('/api/v1/orders/'.$order->id.'/payment-submission/correction', ['channel' => 'yape', 'receiving_account_id' => $account->id, 'operation_number' => '001234568', 'paid_at' => now()->subMinute()->toIso8601String(), 'origin_phone_last_four' => '0140']);
        $this->assertContains($response->getStatusCode(), [409, 422]);
        foreach (['exception', 'trace', 'file', 'line', 'sql'] as $sensitive) {
            $this->assertStringNotContainsString($sensitive, strtolower($response->getContent()));
        }
        $this->assertSame($before[0], [$submission->fresh()->status, $submission->fresh()->decision_reason, $submission->fresh()->reviewed_by, $submission->fresh()->reviewed_at?->format('c'), $submission->fresh()->operation_number]);
        $this->assertSame($before[1], $order->fresh()->reserved_until->format('c'));
        $this->assertSame($before[2], $reservation->fresh()->expires_at->format('c'));
        $this->assertSame($before[3], PaymentSubmissionHistory::count());
    }

    public static function invalidAccountVariants(): array
    {
        return [
            'secondary' => [['is_default' => false]],
            'inactive' => [['is_active' => false, 'is_default' => false]],
            'usd' => [['currency' => 'USD']],
            'incomplete' => [['phone' => null, 'qr_path' => null, 'qr_disk' => null]],
            'wrong channel' => [['channel' => 'plin']],
        ];
    }

    /** @dataProvider ineligibleOrderVariants */
    public function test_ineligible_orders_and_submission_states_are_controlled(string $field, mixed $value): void
    {
        [$owner, $order, $submission, $reservation] = $this->fixture();
        $account = $this->account('yape');
        if ($field === 'submission.status') {
            $submission->update(['status' => $value]);
        } elseif ($field === 'reservation.status') {
            $reservation->update(['status' => $value]);
        } elseif ($field === 'reservation.expires_at') {
            $reservation->update(['expires_at' => now()->subSecond()]);
        } else {
            $order->update([$field => $value === 'past' ? now()->subMinute() : $value]);
        }
        Sanctum::actingAs($owner);
        $this->withHeader('Idempotency-Key', 'ineligible-'.$field)->putJson('/api/v1/orders/'.$order->id.'/payment-submission/correction', ['channel' => 'yape', 'receiving_account_id' => $account->id, 'operation_number' => '001234568', 'paid_at' => now()->subMinute()->toIso8601String(), 'origin_phone_last_four' => '0140'])->assertConflict();
        $this->assertSame(1, PaymentSubmissionHistory::count());
    }

    public static function ineligibleOrderVariants(): array
    {
        return [
            'pending review' => ['submission.status', PaymentSubmission::PENDING_REVIEW], 'rejected' => ['submission.status', PaymentSubmission::REJECTED], 'approved' => ['submission.status', PaymentSubmission::APPROVED], 'expired' => ['submission.status', PaymentSubmission::EXPIRED], 'canceled' => ['submission.status', PaymentSubmission::CANCELED],
            'other method' => ['payment_method', 'card'], 'approved payment' => ['payment_status', 'approved'], 'rejected payment' => ['payment_status', 'rejected'], 'canceled order' => ['status', 'canceled'], 'rejected order' => ['status', 'rejected'], 'delivered order' => ['status', 'delivered'], 'picked up' => ['tracking_status', 'picked_up'], 'expired order' => ['reserved_until', 'past'],
            'released reservation' => ['reservation.status', InventoryReservation::RELEASED], 'consumed reservation' => ['reservation.status', InventoryReservation::CONSUMED], 'expired reservation' => ['reservation.expires_at', true],
        ];
    }

    public function test_identical_and_normalized_equivalent_retries_do_not_write_or_extend_again(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-23 10:00:00', 'America/Lima'));
        try {
            [$owner, $order, $submission, $reservation] = $this->fixture();
            $account = $this->account('yape');
            Sanctum::actingAs($owner);
            $key = 'equivalent-retry';
            $paidAt = Carbon::parse('2026-09-23T09:30:00-05:00');
            $payload = $this->correctionPayload('yape', $account->id, '00-123 4567', $paidAt->toIso8601String());
            $first = $this->withHeader('Idempotency-Key', $key)->putJson($this->correctionUrl($order), $payload)->assertOk();
            $first->assertJsonPath('data.id', $submission->id);
            $before = $this->persistentCorrectionState($submission, $order, $reservation);

            $equivalent = $this->correctionPayload('yape', $account->id, '001234567', $paidAt->setTimezone('UTC')->toIso8601String());
            $second = $this->withHeader('Idempotency-Key', $key)->putJson($this->correctionUrl($order), $equivalent)->assertOk();
            $second->assertJsonPath('data.id', $submission->id)->assertJsonPath('data.status', PaymentSubmission::PENDING_REVIEW);

            $this->assertSame($before, $this->persistentCorrectionState($submission, $order, $reservation));
            $this->assertSame(0, PaymentTransaction::count());
            $this->assertSame(0, InventoryMovement::count());
        } finally {
            Carbon::setTestNow();
        }
    }

    /** @dataProvider semanticDifferenceVariants */
    public function test_same_idempotency_key_with_semantic_difference_conflicts_without_writes(string $field, mixed $value): void
    {
        [$owner, $order, $submission, $reservation] = $this->fixture();
        $account = $this->account('yape');
        $alternate = $this->account('yape', ['is_default' => false]);
        $plin = $this->account('plin');
        Sanctum::actingAs($owner);
        $key = 'semantic-difference';
        $payload = $this->correctionPayload('yape', $account->id, '001234567', now()->subMinute()->toIso8601String());
        $this->withHeader('Idempotency-Key', $key)->putJson($this->correctionUrl($order), $payload)->assertOk();
        $before = $this->persistentCorrectionState($submission, $order, $reservation);
        $changed = match ($field) {
            'operation_number' => array_merge($payload, ['operation_number' => (string) $value]),
            'paid_at' => array_merge($payload, ['paid_at' => now()->subMinutes(2)->toIso8601String()]),
            'channel' => ['channel' => 'plin', 'receiving_account_id' => $plin->id, 'operation_number' => $payload['operation_number'], 'paid_at' => $payload['paid_at'], 'origin_phone_last_four' => '0140'],
            'receiving_account_id' => array_merge($payload, ['receiving_account_id' => $alternate->id]),
            'origin_phone_last_four' => array_merge($payload, ['origin_phone_last_four' => '9999']),
            default => throw new \LogicException('Variante no reconocida.'),
        };
        $response = $this->withHeader('Idempotency-Key', $key)->putJson($this->correctionUrl($order), $changed)->assertConflict();
        $this->assertSafeCorrectionConflict($response, [$key, '001234567', '9999', '0140']);
        $this->assertSame($before, $this->persistentCorrectionState($submission, $order, $reservation));
    }

    public static function semanticDifferenceVariants(): array
    {
        return [
            'operation number' => ['operation_number', '009999999'],
            'declared paid at' => ['paid_at', null],
            'channel' => ['channel', null],
            'receiving account' => ['receiving_account_id', null],
            'source last four' => ['origin_phone_last_four', null],
        ];
    }

    public function test_bank_origin_spacing_and_capitalization_are_not_contractually_equivalent(): void
    {
        [$owner, $order, $submission, $reservation] = $this->fixture();
        $bank = $this->account('bank_transfer');
        Sanctum::actingAs($owner);
        $key = 'bank-origin-key';
        $payload = ['channel' => 'bank_transfer', 'receiving_account_id' => $bank->id, 'operation_number' => '001234567', 'paid_at' => now()->subMinute()->toIso8601String(), 'origin_bank' => 'Banco Origen'];
        $this->withHeader('Idempotency-Key', $key)->putJson($this->correctionUrl($order), $payload)->assertOk();
        $before = $this->persistentCorrectionState($submission, $order, $reservation);
        $response = $this->withHeader('Idempotency-Key', $key)->putJson($this->correctionUrl($order), array_merge($payload, ['origin_bank' => ' banco origen ']))->assertConflict();
        $this->assertSafeCorrectionConflict($response, [$key, 'Banco Origen', 'banco origen']);
        $this->assertSame($before, $this->persistentCorrectionState($submission, $order, $reservation));
    }

    public function test_idempotency_header_is_required_bounded_and_cannot_be_replaced_by_body_input(): void
    {
        [$owner, $order, $submission] = $this->fixture();
        $account = $this->account('yape');
        Sanctum::actingAs($owner);
        $payload = $this->correctionPayload('yape', $account->id, '001234567', now()->subMinute()->toIso8601String());
        $url = $this->correctionUrl($order);
        $this->putJson($url, $payload)->assertUnprocessable();
        $this->withHeader('Idempotency-Key', '   ')->putJson($url, $payload)->assertUnprocessable();
        $this->withHeader('Idempotency-Key', str_repeat('a', 101))->putJson($url, $payload)->assertUnprocessable();
        $this->withHeader('Idempotency-Key', 'header-key')->putJson($url, array_merge($payload, ['idempotency_key' => 'body-key']))->assertUnprocessable();
        $this->withHeader('Idempotency-Key', str_repeat('a', 100))->putJson($url, $payload)->assertOk();
        $this->withHeader('Idempotency-Key', 'new-key')->putJson($url, $payload)->assertConflict();
        $this->assertSame(1, PaymentSubmissionHistory::where('event', 'corrected')->count());
        $this->assertSame(PaymentSubmission::PENDING_REVIEW, $submission->fresh()->status);
    }

    public function test_reused_key_from_another_submission_and_duplicate_fingerprint_are_safe_conflicts(): void
    {
        [$owner, $order, $submission, $reservation] = $this->fixture();
        $account = $this->account('yape');
        Sanctum::actingAs($owner);
        $payload = $this->correctionPayload('yape', $account->id, '001234567', now()->subMinute()->toIso8601String());
        $this->withHeader('Idempotency-Key', 'first-correction-key')->putJson($this->correctionUrl($order), $payload)->assertOk();

        [$otherOwner, $otherOrder, $otherSubmission, $otherReservation] = $this->fixture();
        Sanctum::actingAs($otherOwner);
        $otherBefore = $this->persistentCorrectionState($otherSubmission, $otherOrder, $otherReservation);
        $keyResponse = $this->withHeader('Idempotency-Key', 'first-correction-key')->putJson($this->correctionUrl($otherOrder), $payload)->assertConflict();
        $this->assertSafeCorrectionConflict($keyResponse, ['first-correction-key', '001234567']);
        $this->assertSame($otherBefore, $this->persistentCorrectionState($otherSubmission, $otherOrder, $otherReservation));

        $fingerprintResponse = $this->withHeader('Idempotency-Key', 'other-correction-key')->putJson($this->correctionUrl($otherOrder), $payload)->assertConflict();
        $this->assertSafeCorrectionConflict($fingerprintResponse, ['other-correction-key', '001234567']);
        $this->assertSame($otherBefore, $this->persistentCorrectionState($otherSubmission, $otherOrder, $otherReservation));
        $service = app(\App\Services\TreasuryPaymentService::class);
        $this->assertSame($service->duplicateFingerprint('yape', $account->code, '001234567'), $service->duplicateFingerprint('yape', $account->code, '00-123 4567'));
        $this->assertNotSame($service->duplicateFingerprint('yape', $account->code, '001234567'), $service->duplicateFingerprint('plin', $account->code, '001234567'));
        $otherAccount = $this->account('plin');
        $this->assertNotSame($service->duplicateFingerprint('yape', $account->code, '001234567'), $service->duplicateFingerprint('yape', $otherAccount->code, '001234567'));
    }

    public function test_correction_synchronizes_every_active_reservation_without_shortening_and_has_a_safe_history(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-24 10:00:00', config('app.timezone')));
        try {
            [$owner, $order, $submission, $first, $inventory] = $this->fixture();
            $second = $this->additionalReservation($order, $first, now()->addMinutes(180));
            $first->update(['expires_at' => now()->addMinutes(30)]);
            $order->update(['reserved_until' => now()->addMinutes(60)]);
            config(['treasury.reservation_extension_minutes' => 120]);
            $account = $this->account('yape');
            $inventoryBefore = $inventory->fresh()->only(['quantity', 'reserved_quantity']);
            Sanctum::actingAs($owner);

            $this->withHeader('Idempotency-Key', 'multi-reservation')->putJson($this->correctionUrl($order), $this->correctionPayload('yape', $account->id, '001234567', now()->subMinute()->toIso8601String()))
                ->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Pragma', 'no-cache')
                ->assertJsonMissingPath('data.operation_number')->assertJsonMissingPath('data.origin_phone_last4')->assertJsonMissingPath('data.origin_bank')
                ->assertJsonMissingPath('data.duplicate_fingerprint')->assertJsonMissingPath('data.idempotency_key')->assertJsonMissingPath('data.receiving_account_snapshot');

            $expected = now()->addMinutes(180)->toIso8601String();
            $this->assertSame($expected, $order->fresh()->reserved_until?->toIso8601String());
            $this->assertSame($expected, $first->fresh()->expires_at?->toIso8601String());
            $this->assertSame($expected, $second->fresh()->expires_at?->toIso8601String());
            $history = PaymentSubmissionHistory::where('event', 'corrected')->sole();
            $this->assertSame(PaymentSubmission::OBSERVED, $history->from_status);
            $this->assertSame(PaymentSubmission::PENDING_REVIEW, $history->to_status);
            $this->assertSame($owner->id, $history->actor_id);
            $this->assertSame(120, $history->safe_metadata['configured_minutes']);
            $this->assertSame($expected, $history->safe_metadata['new_expires_at']);
            foreach (['operation_number', 'normalized_operation_number', 'origin_phone_last4', 'origin_bank', 'duplicate_fingerprint', 'idempotency_key', 'receiving_account_snapshot', 'qr'] as $private) {
                $this->assertArrayNotHasKey($private, $history->safe_metadata);
            }
            $this->assertSame($inventoryBefore, $inventory->fresh()->only(['quantity', 'reserved_quantity']));
            $this->assertSame(0, PaymentTransaction::count());
            $this->assertSame(0, InventoryMovement::count());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_correction_leaves_terminal_reservations_untouched_and_rejects_without_an_active_one(): void
    {
        [$owner, $order, $submission, $active] = $this->fixture();
        $terminal = $this->additionalReservation($order, $active, now()->addHours(2), InventoryReservation::RELEASED);
        $account = $this->account('yape');
        Sanctum::actingAs($owner);
        $terminalBefore = [$terminal->status, $terminal->expires_at?->toIso8601String()];
        $this->withHeader('Idempotency-Key', 'terminal-preserved')->putJson($this->correctionUrl($order), $this->correctionPayload('yape', $account->id, '001234567', now()->subMinute()->toIso8601String()))->assertOk();
        $this->assertSame($terminalBefore, [$terminal->fresh()->status, $terminal->fresh()->expires_at?->toIso8601String()]);

        [$invalidOwner, $invalidOrder, $invalidSubmission, $invalidReservation] = $this->fixture();
        $invalidReservation->update(['status' => InventoryReservation::RELEASED]);
        $before = $this->persistentCorrectionState($invalidSubmission, $invalidOrder, $invalidReservation);
        $invalidAccount = $this->account('yape', ['is_default' => false]);
        Sanctum::actingAs($invalidOwner);
        $this->withHeader('Idempotency-Key', 'without-active')->putJson($this->correctionUrl($invalidOrder), $this->correctionPayload('yape', $invalidAccount->id, '001234567', now()->subMinute()->toIso8601String()))->assertConflict();
        $this->assertSame($before, $this->persistentCorrectionState($invalidSubmission, $invalidOrder, $invalidReservation));
    }

    public function test_non_unique_corrected_history_failure_rolls_back_the_entire_correction(): void
    {
        [$owner, $order, $submission, $first, $inventory] = $this->fixture();
        $second = $this->additionalReservation($order, $first, now()->addMinutes(90));
        $account = $this->account('yape');
        $before = $this->persistentCorrectionState($submission, $order, $first);
        $secondBefore = $second->expires_at?->toIso8601String();
        $financialBefore = $inventory->fresh()->only(['quantity', 'reserved_quantity']);
        PaymentSubmissionHistory::creating(fn () => throw new \RuntimeException('forced corrected history failure'));

        try {
            app(\App\Services\CustomerPaymentSubmissionCorrectionService::class)->correct(
                $order,
                $owner->id,
                $this->correctionPayload('yape', $account->id, '009876543', now()->subMinute()->toIso8601String()),
                'history-rollback',
            );
            $this->fail('La falla de historial no interrumpió la transacción de corrección.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('forced corrected history failure', $exception->getMessage());
        } finally {
            PaymentSubmissionHistory::flushEventListeners();
        }

        $this->assertSame($before, $this->persistentCorrectionState($submission, $order, $first));
        $this->assertSame($secondBefore, $second->fresh()->expires_at?->toIso8601String());
        $this->assertSame($financialBefore, $inventory->fresh()->only(['quantity', 'reserved_quantity']));
        $this->assertSame(0, PaymentSubmissionHistory::where('event', 'corrected')->count());
    }

    public function test_reservation_update_failure_rolls_back_the_complete_correction_after_history_and_order_mutations(): void
    {
        if (\Illuminate\Support\Facades\DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('SQLite-specific RAISE(ABORT) trigger; rollback is covered in SQLite and MySQL transactional/concurrency certification.');
        }

        [$owner, $order, $submission, $first, $inventory] = $this->fixture();
        $second = $this->additionalReservation($order, $first, now()->addMinutes(90));
        $account = $this->account('yape');
        $before = $this->persistentCorrectionState($submission, $order, $first);
        $secondBefore = [$second->status, $second->expires_at?->toIso8601String()];
        $orderBefore = $this->financialOrderState($order);
        $itemsBefore = $order->items()->orderBy('id')->get()->map(fn ($item) => $item->only(['id', 'quantity']))->all();
        $inventoryBefore = $inventory->fresh()->only(['quantity', 'reserved_quantity']);
        \Illuminate\Support\Facades\DB::statement("CREATE TRIGGER force_reservation_expiry_failure BEFORE UPDATE OF expires_at ON inventory_reservations BEGIN SELECT RAISE(ABORT, 'forced reservation expiry failure'); END");

        try {
            app(\App\Services\CustomerPaymentSubmissionCorrectionService::class)->correct(
                $order,
                $owner->id,
                $this->correctionPayload('yape', $account->id, '009876543', now()->subMinute()->toIso8601String()),
                'reservation-update-rollback',
            );
            $this->fail('El fallo de actualización de reservas no interrumpió la transacción.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('forced reservation expiry failure', $exception->getMessage());
        } finally {
            \Illuminate\Support\Facades\DB::statement('DROP TRIGGER IF EXISTS force_reservation_expiry_failure');
        }

        $this->assertSame($before, $this->persistentCorrectionState($submission, $order, $first));
        $this->assertSame($secondBefore, [$second->fresh()->status, $second->fresh()->expires_at?->toIso8601String()]);
        $this->assertSame($orderBefore, $this->financialOrderState($order));
        $this->assertSame($itemsBefore, $order->items()->orderBy('id')->get()->map(fn ($item) => $item->only(['id', 'quantity']))->all());
        $this->assertSame($inventoryBefore, $inventory->fresh()->only(['quantity', 'reserved_quantity']));
        $this->assertSame(0, PaymentSubmissionHistory::where('event', 'corrected')->count());
        $this->assertSame(0, PaymentTransaction::count());
        $this->assertSame(0, InventoryMovement::count());
    }

    public function test_recognized_unique_collision_returns_safe_conflict_and_rolls_back_correction(): void
    {
        [$owner, $order, $submission, $reservation] = $this->fixture();
        $account = $this->account('yape');
        $before = [
            'status' => $submission->status,
            'operation_number' => $submission->operation_number,
            'normalized_operation_number' => $submission->normalized_operation_number,
            'channel' => $submission->channel,
            'origin_phone_last4' => $submission->origin_phone_last4,
            'origin_bank' => $submission->origin_bank,
            'duplicate_fingerprint' => $submission->duplicate_fingerprint,
            'idempotency_key' => $submission->idempotency_key,
            'receiving_account_snapshot' => $submission->receiving_account_snapshot,
            'submitted_at' => $submission->submitted_at?->toIso8601String(),
            'decision_reason' => $submission->decision_reason,
            'reviewed_by' => $submission->reviewed_by,
            'reviewed_at' => $submission->reviewed_at?->toIso8601String(),
            'reserved_until' => $order->reserved_until?->toIso8601String(),
            'reservation_expires_at' => $reservation->expires_at?->toIso8601String(),
            'history_count' => PaymentSubmissionHistory::count(),
        ];
        PaymentSubmissionHistory::creating(function (): void {
            throw $this->uniqueQueryException();
        });

        try {
            Sanctum::actingAs($owner);
            $response = $this->withHeader('Idempotency-Key', 'race-unique-key')->putJson('/api/v1/orders/'.$order->id.'/payment-submission/correction', [
                'channel' => 'yape',
                'receiving_account_id' => $account->id,
                'operation_number' => '009876543',
                'paid_at' => now()->subMinute()->toIso8601String(),
                'origin_phone_last_four' => '0140',
            ])->assertConflict()->assertJsonPath('code', 'payment_submission_correction_conflict');
        } finally {
            PaymentSubmissionHistory::flushEventListeners();
        }

        foreach (['exception', 'trace', 'file', 'line', 'sql', 'bindings', 'constraint', 'fingerprint', 'race-unique-key', '009876543', 'snapshot'] as $sensitive) {
            $this->assertStringNotContainsString(strtolower($sensitive), strtolower($response->getContent()));
        }
        $fresh = $submission->fresh();
        $this->assertSame($before['status'], $fresh->status);
        $this->assertSame($before['operation_number'], $fresh->operation_number);
        $this->assertSame($before['normalized_operation_number'], $fresh->normalized_operation_number);
        $this->assertSame($before['channel'], $fresh->channel);
        $this->assertSame($before['origin_phone_last4'], $fresh->origin_phone_last4);
        $this->assertSame($before['origin_bank'], $fresh->origin_bank);
        $this->assertSame($before['duplicate_fingerprint'], $fresh->duplicate_fingerprint);
        $this->assertSame($before['idempotency_key'], $fresh->idempotency_key);
        $this->assertSame($before['receiving_account_snapshot'], $fresh->receiving_account_snapshot);
        $this->assertSame($before['submitted_at'], $fresh->submitted_at?->toIso8601String());
        $this->assertSame($before['decision_reason'], $fresh->decision_reason);
        $this->assertSame($before['reviewed_by'], $fresh->reviewed_by);
        $this->assertSame($before['reviewed_at'], $fresh->reviewed_at?->toIso8601String());
        $this->assertSame($before['reserved_until'], $order->fresh()->reserved_until?->toIso8601String());
        $this->assertSame($before['reservation_expires_at'], $reservation->fresh()->expires_at?->toIso8601String());
        $this->assertSame($before['history_count'], PaymentSubmissionHistory::count());
    }

    private function fixture(): array
    {
        $owner = User::factory()->create();
        $suffix = (string) (Order::count() + 1);
        $branch = Branch::create(['code' => 'B'.$suffix, 'name' => 'B', 'address' => 'A', 'is_active' => true]);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'code' => 'W'.$suffix, 'name' => 'W', 'is_active' => true, 'is_default' => false]);
        $product = Product::create(['name' => 'P'.$suffix, 'slug' => 'p-'.$suffix, 'sku' => 'P'.$suffix, 'price' => '20.00', 'sale_price' => '20.00', 'is_active' => true]);
        $inventory = WarehouseInventory::create(['warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'quantity' => 2, 'reserved_quantity' => 1]);
        $order = Order::create(['user_id' => $owner->id, 'status' => 'pending', 'total' => '20.00', 'shipping_info' => ['recipient_name' => 'C'], 'payment_method' => 'transferencia', 'payment_status' => 'pending', 'reserved_until' => now()->addHour(), 'delivery_type' => 'delivery', 'tracking_status' => 'pending']);
        $item = $order->items()->create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'quantity' => 1, 'price' => '20.00', 'subtotal' => '20.00']);
        $reservation = InventoryReservation::create(['order_id' => $order->id, 'order_item_id' => $item->id, 'product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'quantity' => 1, 'status' => InventoryReservation::ACTIVE, 'expires_at' => now()->addHour(), 'idempotency_key' => 'r'.$suffix]);
        $submission = PaymentSubmission::create(['order_id' => $order->id, 'channel' => 'yape', 'status' => PaymentSubmission::OBSERVED, 'expected_amount' => '20.00', 'currency' => 'PEN', 'operation_number' => '001234567', 'normalized_operation_number' => '001234567', 'duplicate_fingerprint' => hash('sha256', 'old'), 'declared_paid_at' => now()->subMinute(), 'origin_phone_last4' => '0140', 'receiving_account_snapshot' => ['id' => 1], 'submitted_by' => $owner->id, 'submitted_at' => now()->subMinutes(3), 'reviewed_by' => $owner->id, 'reviewed_at' => now(), 'decision_reason' => 'Revise', 'idempotency_key' => 'initial']);
        PaymentSubmissionHistory::create(['payment_submission_id' => $submission->id, 'event' => 'observed', 'from_status' => 'pending_review', 'to_status' => 'observed', 'actor_id' => $owner->id, 'reason' => 'Revise', 'occurred_at' => now()]);

        return [$owner, $order, $submission, $reservation, $inventory];
    }

    private function account(string $channel, array $overrides = []): PaymentReceivingAccount
    {
        $mobile = in_array($channel, ['yape', 'plin'], true);

        return PaymentReceivingAccount::create(array_merge(['code' => strtoupper($channel).'-'.(PaymentReceivingAccount::count() + 1), 'channel' => $channel, 'display_name' => 'Cuenta', 'holder_name' => 'Titular', 'currency' => 'PEN', 'phone' => $mobile ? '999999999' : null, 'qr_path' => $mobile ? 'q.png' : null, 'qr_disk' => $mobile ? 'treasury_qr' : null, 'bank_name' => $channel === 'bank_transfer' ? 'Banco' : null, 'account_number' => $channel === 'bank_transfer' ? '123456' : null, 'is_active' => true, 'is_default' => true], $overrides));
    }

    private function uniqueQueryException(): QueryException
    {
        $previous = new PDOException('UNIQUE constraint failed: payment_submission_histories.id', 19);
        $previous->errorInfo = ['HY000', 19, 'UNIQUE constraint failed: payment_submission_histories.id'];

        return new QueryException('insert into payment_submission_histories values (?)', [], $previous);
    }

    private function correctionUrl(Order $order): string
    {
        return '/api/v1/orders/'.$order->id.'/payment-submission/correction';
    }

    private function correctionPayload(string $channel, int $accountId, string $operation, string $paidAt): array
    {
        return ['channel' => $channel, 'receiving_account_id' => $accountId, 'operation_number' => $operation, 'paid_at' => $paidAt, 'origin_phone_last_four' => '0140'];
    }

    private function additionalReservation(Order $order, InventoryReservation $base, Carbon $expiresAt, string $status = InventoryReservation::ACTIVE): InventoryReservation
    {
        $suffix = (string) (InventoryReservation::count() + 10);
        $product = Product::create(['name' => 'P-extra-'.$suffix, 'slug' => 'p-extra-'.$suffix, 'sku' => 'P-extra-'.$suffix, 'price' => '20.00', 'sale_price' => '20.00', 'is_active' => true]);
        WarehouseInventory::create(['warehouse_id' => $base->warehouse_id, 'product_id' => $product->id, 'quantity' => 2, 'reserved_quantity' => 1]);
        $item = $order->items()->create(['product_id' => $product->id, 'warehouse_id' => $base->warehouse_id, 'quantity' => 1, 'price' => '20.00', 'subtotal' => '20.00']);

        return InventoryReservation::create(['order_id' => $order->id, 'order_item_id' => $item->id, 'product_id' => $product->id, 'warehouse_id' => $base->warehouse_id, 'quantity' => 1, 'status' => $status, 'expires_at' => $expiresAt, 'idempotency_key' => 'extra-'.$order->id.'-'.$suffix]);
    }

    private function persistentCorrectionState(PaymentSubmission $submission, Order $order, InventoryReservation $reservation): array
    {
        $submission = $submission->fresh();

        return [
            'submission' => $submission->only(['status', 'operation_number', 'normalized_operation_number', 'channel', 'origin_phone_last4', 'origin_bank', 'expected_amount', 'currency', 'duplicate_fingerprint', 'idempotency_key', 'receiving_account_snapshot']),
            'declared_paid_at' => $submission->declared_paid_at?->toIso8601String(),
            'submitted_at' => $submission->submitted_at?->toIso8601String(),
            'updated_at' => $submission->updated_at?->toIso8601String(),
            'reviewed_at' => $submission->reviewed_at?->toIso8601String(),
            'reviewed_by' => $submission->reviewed_by,
            'decision_reason' => $submission->decision_reason,
            'reserved_until' => $order->fresh()->reserved_until?->toIso8601String(),
            'reservation_expires_at' => $reservation->fresh()->expires_at?->toIso8601String(),
            'history_count' => PaymentSubmissionHistory::count(),
            'transactions' => PaymentTransaction::count(),
            'movements' => InventoryMovement::count(),
        ];
    }

    private function financialOrderState(Order $order): array
    {
        $order = $order->fresh();

        return [
            'status' => $order->status,
            'fulfillment_status' => $order->fulfillment_status,
            'payment_status' => $order->payment_status,
            'paid_at' => $order->paid_at?->toIso8601String(),
            'reserved_until' => $order->reserved_until?->toIso8601String(),
        ];
    }

    private function assertSafeCorrectionConflict($response, array $sensitiveValues): void
    {
        foreach (array_merge(['exception', 'trace', 'file', 'line', 'sql', 'bindings', 'constraint', 'duplicate_fingerprint', 'idempotency_key', 'snapshot'], $sensitiveValues) as $sensitive) {
            $this->assertStringNotContainsString(strtolower($sensitive), strtolower($response->getContent()));
        }
    }
}
