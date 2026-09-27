<?php

namespace Tests\Feature;

use App\Http\Middleware\IsTreasury;
use App\Http\Resources\UserResource;
use App\Models\Order;
use App\Models\PaymentSubmission;
use App\Models\PaymentSubmissionHistory;
use App\Models\User;
use App\Services\TreasuryPaymentService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;
use Laravel\Sanctum\Sanctum;
use PDOException;
use Tests\TestCase;

class TreasuryInfrastructureTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_registration_remains_customer_and_admin_can_create_treasury(): void
    {
        $this->postJson('/api/v1/auth/register', ['name' => 'Cliente', 'email' => 'client@example.test', 'password' => 'password123', 'password_confirmation' => 'password123', 'role' => 'treasury'])->assertCreated()->assertJsonPath('user.role', 'customer');
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->postJson('/api/v1/admin/users', ['name' => 'Caja', 'email' => 'treasury@example.test', 'password' => 'password123', 'password_confirmation' => 'password123', 'role' => 'treasury'])->assertCreated()->assertJsonPath('data.role', 'treasury');
    }

    public function test_treasury_is_not_admin_but_passes_only_treasury_middleware_when_active(): void
    {
        Route::middleware(['auth:sanctum', 'is_treasury'])->get('/api/treasury-test', fn () => ['ok' => true]);
        $treasury = User::factory()->create(['role' => 'treasury', 'is_active' => true]);
        Sanctum::actingAs($treasury);
        $this->getJson('/api/treasury-test')->assertOk();
        $this->getJson('/api/v1/admin/orders')->assertForbidden();
        $inactive = new User(['role' => 'treasury', 'is_active' => false]);
        $request = Request::create('/api/treasury-test');
        $request->setUserResolver(fn () => $inactive);
        $this->assertSame(403, (new IsTreasury)->handle($request, fn () => response()->json(['ok' => true]))->getStatusCode());
    }

    public function test_submission_constraints_history_immutability_and_service_rules(): void
    {
        $order = Order::create(['user_id' => User::factory()->create()->id, 'status' => 'pending', 'total' => '25.50', 'shipping_info' => [], 'payment_method' => 'transferencia', 'payment_status' => 'pending', 'delivery_type' => 'delivery', 'tracking_status' => 'pending']);
        $user = User::factory()->create();
        $service = app(TreasuryPaymentService::class);
        $number = $service->normalizeOperationNumber(' op- 12/ab ');
        $this->assertSame('OP12AB', $number);
        $fingerprint = $service->duplicateFingerprint('yape', 'ACCOUNT-1', $number);
        $this->assertSame($fingerprint, $service->duplicateFingerprint('yape', 'ACCOUNT-1', $number));
        $this->assertNotSame($fingerprint, $service->duplicateFingerprint('plin', 'ACCOUNT-1', $number));
        $service->validateSubmission('yape', '1234', null);
        $service->validateSubmission('bank_transfer', null, 'Banco');
        $service->assertExpectedAmount($order, '25.50');
        $submission = PaymentSubmission::create(['order_id' => $order->id, 'channel' => 'yape', 'status' => 'pending_review', 'expected_amount' => '25.50', 'currency' => 'PEN', 'operation_number' => 'OP-12', 'normalized_operation_number' => 'OP12', 'duplicate_fingerprint' => $fingerprint, 'declared_paid_at' => now(), 'origin_phone_last4' => '1234', 'receiving_account_snapshot' => ['identifier' => 'ACCOUNT-1'], 'submitted_by' => $user->id, 'submitted_at' => now(), 'idempotency_key' => 'submission-'.$order->id]);
        $history = PaymentSubmissionHistory::create(['payment_submission_id' => $submission->id, 'event' => 'submitted', 'to_status' => 'pending_review', 'actor_id' => $user->id, 'occurred_at' => now()]);
        $this->expectException(\LogicException::class);
        $history->update(['reason' => 'no']);
    }

    public function test_operation_number_normalization_is_stable_and_preserves_leading_zeros(): void
    {
        $service = app(TreasuryPaymentService::class);
        $this->assertSame('OP12AB', $service->normalizeOperationNumber(' op- 12/ab '));
        $this->assertSame('0012', $service->normalizeOperationNumber(' 00-12 '));
        $this->assertSame('ABC123', $service->normalizeOperationNumber('abc.123'));
        $this->assertSame(str_repeat('A', 100), $service->normalizeOperationNumber(str_repeat('a', 100)));

        foreach (['', '---', 'ó', str_repeat('A', 101), 1234] as $invalid) {
            try {
                $service->normalizeOperationNumber($invalid);
                $this->fail('El número inválido fue aceptado.');
            } catch (\Illuminate\Validation\ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_channel_rules_fingerprints_and_exact_decimal_amounts_are_safe(): void
    {
        $service = app(TreasuryPaymentService::class);
        $this->assertSame('yape', $service->validateSubmission(' YAPE ', '1234', null)['channel']);
        $this->assertSame('plin', $service->validateSubmission('plin', '9999', null)['channel']);
        $this->assertSame('Banco', $service->validateSubmission('bank_transfer', null, ' Banco ')['bank']);
        foreach ([['yape', null, null], ['plin', '123', null], ['plin', '12345', null], ['yape', '12A4', null], ['unknown', '1234', null], ['bank_transfer', null, str_repeat('B', 121)]] as [$channel, $last4, $bank]) {
            try {
                $service->validateSubmission($channel, $last4, $bank);
                $this->fail('El canal inválido fue aceptado.');
            } catch (\Illuminate\Validation\ValidationException) {
                $this->addToAssertionCount(1);
            }
        }

        $fingerprint = $service->duplicateFingerprint('yape', ' ACCOUNT-1 ', ' op-01 ');
        $this->assertSame($fingerprint, $service->duplicateFingerprint('yape', 'ACCOUNT 1', 'OP01'));
        $this->assertNotSame($fingerprint, $service->duplicateFingerprint('plin', 'ACCOUNT-1', 'OP01'));
        $this->assertNotSame($fingerprint, $service->duplicateFingerprint('yape', 'ACCOUNT-2', 'OP01'));
        $this->assertStringNotContainsString('OP01', $fingerprint);

        $order = Order::create(['user_id' => User::factory()->create()->id, 'status' => 'pending', 'total' => '0.30', 'shipping_info' => [], 'payment_method' => 'transferencia', 'payment_status' => 'pending', 'delivery_type' => 'delivery', 'tracking_status' => 'pending']);
        $service->assertExpectedAmount($order, '0.30');
        $service->assertExpectedAmount($order, '0.3');
        foreach (['0.29', '0.31', '-0.30', 'NaN', 'Infinity', null, 0.1 + 0.2] as $invalid) {
            try {
                $service->assertExpectedAmount($order, $invalid);
                $this->fail('El importe inseguro fue aceptado.');
            } catch (\Illuminate\Validation\ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_model_validation_history_safety_and_state_machine_are_constrained(): void
    {
        $order = Order::create(['user_id' => User::factory()->create()->id, 'status' => 'pending', 'total' => '25.50', 'shipping_info' => [], 'payment_method' => 'transferencia', 'payment_status' => 'pending', 'delivery_type' => 'delivery', 'tracking_status' => 'pending']);
        $user = User::factory()->create();
        $submission = PaymentSubmission::create(['order_id' => $order->id, 'channel' => 'yape', 'expected_amount' => '25.50', 'operation_number' => 'OP-12', 'normalized_operation_number' => 'OP12', 'duplicate_fingerprint' => hash('sha256', 'x'), 'declared_paid_at' => now(), 'receiving_account_snapshot' => ['identifier' => 'ACCOUNT-1'], 'submitted_by' => $user->id, 'submitted_at' => now(), 'idempotency_key' => 'submission-'.$order->id]);
        $this->assertSame(PaymentSubmission::PENDING_REVIEW, $submission->status);
        $this->assertSame('PEN', $submission->currency);
        $this->assertIsArray($submission->receiving_account_snapshot);
        $this->assertArrayNotHasKey('receiving_account_snapshot', $submission->toArray());
        $this->assertArrayNotHasKey('operation_number', $submission->toArray());
        $submission->channel = 'unknown';
        try {
            $submission->save();
            $this->fail('El modelo aceptó un canal arbitrario.');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        $history = PaymentSubmissionHistory::create(['payment_submission_id' => $submission->id, 'event' => 'submitted', 'to_status' => 'pending_review', 'actor_id' => $user->id, 'safe_metadata' => app(TreasuryPaymentService::class)->safeHistoryMetadata(['channel' => 'yape', 'token' => 'secret', 'request' => ['password' => 'secret']]), 'occurred_at' => now()]);
        $this->assertArrayNotHasKey('token', $history->safe_metadata);
        $this->assertArrayNotHasKey('request', $history->safe_metadata);
        $this->assertSame($submission->id, $history->submission->id);

        $service = app(TreasuryPaymentService::class);
        $this->assertTrue($service->canTransition(PaymentSubmission::PENDING_REVIEW, PaymentSubmission::APPROVED));
        $this->assertTrue($service->canTransition(PaymentSubmission::OBSERVED, PaymentSubmission::PENDING_REVIEW));
        foreach ([PaymentSubmission::APPROVED, PaymentSubmission::REJECTED, PaymentSubmission::EXPIRED, PaymentSubmission::CANCELED] as $terminal) {
            $this->assertFalse($service->canTransition($terminal, PaymentSubmission::PENDING_REVIEW));
        }
    }

    public function test_treasury_role_resource_is_safe_and_non_administrative(): void
    {
        $treasury = User::factory()->create(['role' => 'treasury']);
        $admin = User::factory()->create(['role' => 'admin']);
        $customer = User::factory()->create(['role' => 'customer']);
        $data = (new UserResource($treasury))->resolve(Request::create('/api/v1/admin/users'));

        $this->assertSame('Tesorería', $data['role_label']);
        $this->assertFalse($treasury->isAdmin());
        $this->assertFalse($admin->isTreasury());
        $this->assertFalse($customer->isTreasury());
        $this->assertArrayNotHasKey('password', $data);
        $this->assertArrayNotHasKey('remember_token', $data);
    }

    public function test_database_uniqueness_and_history_deletion_protection_are_backed_by_schema(): void
    {
        $order = Order::create(['user_id' => User::factory()->create()->id, 'status' => 'pending', 'total' => '25.50', 'shipping_info' => [], 'payment_method' => 'transferencia', 'payment_status' => 'pending', 'delivery_type' => 'delivery', 'tracking_status' => 'pending']);
        $user = User::factory()->create();
        $attributes = ['order_id' => $order->id, 'channel' => 'yape', 'expected_amount' => '25.50', 'operation_number' => 'OP12', 'normalized_operation_number' => 'OP12', 'duplicate_fingerprint' => hash('sha256', 'one'), 'declared_paid_at' => now(), 'receiving_account_snapshot' => ['identifier' => 'ACCOUNT1'], 'submitted_by' => $user->id, 'submitted_at' => now(), 'idempotency_key' => 'one'];
        $submission = PaymentSubmission::create($attributes);
        try {
            PaymentSubmission::create(array_merge($attributes, ['duplicate_fingerprint' => hash('sha256', 'two'), 'idempotency_key' => 'two']));
            $this->fail('La restricción única por pedido no se aplicó.');
        } catch (\Illuminate\Database\QueryException) {
            $this->addToAssertionCount(1);
        }

        $history = PaymentSubmissionHistory::create(['payment_submission_id' => $submission->id, 'event' => 'submitted', 'to_status' => 'pending_review', 'occurred_at' => now()]);
        $this->expectException(\LogicException::class);
        $history->delete();
    }

    public function test_unique_violation_classifier_is_specific_to_supported_database_drivers(): void
    {
        $service = app(TreasuryPaymentService::class);

        foreach ([
            'postgres unique SQLSTATE' => ['23505', 0, 'duplicate key value violates unique constraint', true],
            'mysql duplicate driver code' => ['23000', 1062, 'Duplicate entry', true],
            'sqlite unique constraint' => ['HY000', 19, 'UNIQUE constraint failed: payment_submissions.idempotency_key', true],
            'sqlite generic constraint' => ['HY000', 19, 'FOREIGN KEY constraint failed', false],
            'mysql generic integrity SQLSTATE' => ['23000', 0, 'Integrity constraint violation', false],
            'foreign key violation' => ['23000', 1452, 'Cannot add or update a child row: a foreign key constraint fails', false],
            'not null violation' => ['23000', 1048, "Column 'channel' cannot be null", false],
            'connection error' => ['HY000', 2002, 'Connection refused', false],
            'syntax error' => ['42000', 1064, 'You have an error in your SQL syntax', false],
            'generic query exception' => ['HY000', 0, 'Database error', false],
        ] as $label => [$state, $driverCode, $message, $expected]) {
            $this->assertSame($expected, $service->isUniqueViolation($this->queryException($state, $driverCode, $message)), $label);
        }
    }

    public function test_reservation_extension_configuration_uses_bounded_environment_defaults(): void
    {
        foreach ([
            'observation valid' => ['TREASURY_OBSERVATION_CORRECTION_MINUTES', 'observation_correction_minutes', '45', 45],
            'reservation valid' => ['TREASURY_RESERVATION_EXTENSION_MINUTES', 'reservation_extension_minutes', '90', 90],
            'observation zero' => ['TREASURY_OBSERVATION_CORRECTION_MINUTES', 'observation_correction_minutes', '0', 120],
            'reservation negative' => ['TREASURY_RESERVATION_EXTENSION_MINUTES', 'reservation_extension_minutes', '-1', 120],
            'observation non numeric' => ['TREASURY_OBSERVATION_CORRECTION_MINUTES', 'observation_correction_minutes', 'invalid', 120],
            'reservation excessive' => ['TREASURY_RESERVATION_EXTENSION_MINUTES', 'reservation_extension_minutes', '1441', 120],
        ] as $label => [$environmentKey, $configKey, $value, $expected]) {
            putenv($environmentKey.'='.$value);
            try {
                $treasury = require base_path('config/treasury.php');
                $this->assertSame($expected, $treasury[$configKey], $label);
            } finally {
                putenv($environmentKey);
            }
        }
    }

    private function queryException(string $state, int $driverCode, string $message): QueryException
    {
        $previous = new PDOException($message, $driverCode);
        $previous->errorInfo = [$state, $driverCode, $message];

        return new QueryException('insert into payment_submissions values (?)', [], $previous);
    }
}
