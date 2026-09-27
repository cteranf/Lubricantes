<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\PaymentSubmission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminOrdersTreasurySubmissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_order_list_exposes_only_safe_payment_submission_summary_without_per_row_queries(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $withSubmission = $this->order();
        $withoutSubmission = $this->order();
        $this->submission($withSubmission, PaymentSubmission::PENDING_REVIEW);

        DB::flushQueryLog();
        DB::enableQueryLog();
        Sanctum::actingAs($admin);
        $response = $this->getJson('/api/v1/admin/orders')->assertOk();
        $paymentSubmissionQueries = collect(DB::getQueryLog())
            ->filter(fn (array $query) => str_contains(strtolower($query['query']), 'payment_submissions'));
        DB::disableQueryLog();

        $orders = collect($response->json('data'))->keyBy('id');
        $this->assertTrue((bool) data_get($orders, $withSubmission->id.'.has_payment_submission'));
        $this->assertSame(PaymentSubmission::PENDING_REVIEW, data_get($orders, $withSubmission->id.'.payment_submission_status'));
        $this->assertFalse((bool) data_get($orders, $withoutSubmission->id.'.has_payment_submission'));
        $this->assertNull(data_get($orders, $withoutSubmission->id.'.payment_submission_status'));
        $this->assertLessThanOrEqual(2, $paymentSubmissionQueries->count());

        foreach (['"payment_submission":', 'operation_number', 'normalized_operation_number', 'duplicate_fingerprint', 'idempotency_key', 'receiving_account_snapshot', 'origin_phone_last4', 'origin_bank', 'safe_metadata'] as $sensitive) {
            $this->assertStringNotContainsString($sensitive, strtolower($response->getContent()));
        }
    }

    /** @dataProvider submissionStatuses */
    public function test_admin_fulfillment_serializes_the_real_submission_status_and_disables_legacy_approval(string $status): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $order = $this->order();
        $this->submission($order, $status);

        Sanctum::actingAs($admin);
        $response = $this->getJson('/api/v1/admin/orders/'.$order->id.'/fulfillment')->assertOk();

        $response
            ->assertJsonPath('order.has_payment_submission', true)
            ->assertJsonPath('order.payment_submission_status', $status)
            ->assertJsonPath('fulfillment.actions.approve_payment', false);
        foreach (['"payment_submission":', 'operation_number', 'normalized_operation_number', 'duplicate_fingerprint', 'idempotency_key', 'receiving_account_snapshot', 'origin_phone_last4', 'origin_bank', 'safe_metadata'] as $sensitive) {
            $this->assertStringNotContainsString($sensitive, strtolower($response->getContent()));
        }
    }

    public function test_admin_fulfillment_keeps_legacy_approval_available_without_a_payment_submission(): void
    {
        $order = $this->order();
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        $this->getJson('/api/v1/admin/orders/'.$order->id.'/fulfillment')
            ->assertOk()
            ->assertJsonPath('order.has_payment_submission', false)
            ->assertJsonPath('order.payment_submission_status', null)
            ->assertJsonPath('fulfillment.actions.approve_payment', true);
    }

    public static function submissionStatuses(): array
    {
        return [
            'pending review' => [PaymentSubmission::PENDING_REVIEW],
            'observed' => [PaymentSubmission::OBSERVED],
            'approved' => [PaymentSubmission::APPROVED],
            'rejected' => [PaymentSubmission::REJECTED],
            'expired' => [PaymentSubmission::EXPIRED],
            'canceled' => [PaymentSubmission::CANCELED],
        ];
    }

    private function order(): Order
    {
        $owner = User::factory()->create();

        return Order::create([
            'user_id' => $owner->id,
            'status' => 'pending',
            'fulfillment_status' => Order::FULFILLMENT_RESERVED,
            'total' => '20.00',
            'shipping_info' => ['address' => 'Prueba'],
            'payment_method' => 'transferencia',
            'payment_status' => 'pending',
            'reserved_until' => now()->addHour(),
            'delivery_type' => 'delivery',
            'tracking_status' => 'pending',
        ]);
    }

    private function submission(Order $order, string $status): PaymentSubmission
    {
        return PaymentSubmission::create([
            'order_id' => $order->id,
            'channel' => 'yape',
            'status' => $status,
            'expected_amount' => '20.00',
            'currency' => 'PEN',
            'operation_number' => '000123456',
            'normalized_operation_number' => '000123456',
            'duplicate_fingerprint' => hash('sha256', Str::uuid()->toString()),
            'declared_paid_at' => now(),
            'origin_phone_last4' => '1234',
            'receiving_account_snapshot' => ['id' => 1],
            'submitted_by' => $order->user_id,
            'submitted_at' => now(),
            'idempotency_key' => 'submission-'.Str::uuid(),
        ]);
    }
}
