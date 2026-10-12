<?php

namespace Tests\Feature;

use App\Exceptions\PaymentRefundConflictException;
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
use App\Services\InventoryBalanceLockService;
use App\Services\TreasuryPaymentRefundService;
use App\Services\TreasuryPaymentService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDOException;
use RuntimeException;
use Tests\TestCase;

class TreasuryPaymentRefundServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_marks_a_total_refund_pending_and_preserves_the_original_payment_and_compensated_inventory(): void
    {
        $fixture = $this->fixture(withSubmission: true, withResolutionCase: true);
        $inventoryBefore = $this->inventorySnapshot($fixture);
        $transactionBefore = $this->financialEntitySnapshot($fixture['transaction']->fresh()->getAttributes());
        $caseBefore = $this->financialEntitySnapshot($fixture['case']->fresh()->getAttributes());

        $refund = $this->service()->markPending($fixture['order'], $fixture['treasury'], 'Devolución total solicitada');

        $this->assertSame(PaymentRefund::PENDING, $refund->status);
        $this->assertSame((int) $fixture['transaction']->id, (int) $refund->original_payment_transaction_id);
        $this->assertNull($refund->payment_resolution_case_id);
        $this->assertNull($refund->refund_payment_transaction_id);
        $this->assertSame('50.00', (string) $refund->amount);
        $this->assertSame('PEN', $refund->currency);
        $this->assertSame('refund_pending', $fixture['order']->fresh()->payment_status);
        $this->assertSame(PaymentSubmission::REFUND_PENDING, $fixture['submission']->fresh()->status);
        $this->assertSnapshotUnchanged(
            $transactionBefore,
            $this->financialEntitySnapshot($fixture['transaction']->fresh()->getAttributes())
        );
        $this->assertSnapshotUnchanged(
            $caseBefore,
            $this->financialEntitySnapshot($fixture['case']->fresh()->getAttributes())
        );
        $this->assertSnapshotUnchanged($inventoryBefore, $this->inventorySnapshot($fixture));

        $history = PaymentSubmissionHistory::where('payment_submission_id', $fixture['submission']->id)
            ->where('event', PaymentSubmissionHistory::REFUND_MARKED_PENDING)->sole();
        $this->assertSame(PaymentSubmission::APPROVED, $history->from_status);
        $this->assertSame(PaymentSubmission::REFUND_PENDING, $history->to_status);
        $this->assertSame((int) $fixture['treasury']->id, (int) $history->actor_id);
        $this->assertTrue($history->occurred_at->equalTo($refund->requested_at));
        $this->assertSame(['amount', 'currency'], $this->sortedKeys($history->safe_metadata));
        $this->assertSame(0, PaymentTransaction::where('transaction_type', PaymentTransaction::REFUND)->where('order_id', $fixture['order']->id)->count());
    }

    /** @dataProvider paymentMethodsWithoutSubmission */
    public function test_supports_approved_payment_methods_without_fabricating_a_submission_or_resolution_case(string $method): void
    {
        $fixture = $this->fixture(paymentMethod: $method);

        $refund = $this->service()->markPending($fixture['order'], $fixture['treasury'], 'Solicitud manual');

        $this->assertSame(PaymentRefund::PENDING, $refund->status);
        $this->assertNull($refund->payment_resolution_case_id);
        $this->assertSame(0, PaymentSubmission::where('order_id', $fixture['order']->id)->count());
        $this->assertSame(0, PaymentResolutionCase::count());
        $this->assertSame(0, PaymentSubmissionHistory::where('event', PaymentSubmissionHistory::REFUND_MARKED_PENDING)->count());
    }

    public static function paymentMethodsWithoutSubmission(): array
    {
        return [
            'mercado pago' => ['mercadopago'],
            'contra entrega' => ['contra_entrega'],
            'pago en sede' => ['pago_en_sede'],
        ];
    }

    public function test_a_coherent_retry_returns_the_same_refund_without_writes(): void
    {
        $fixture = $this->fixture(withSubmission: true, withResolutionCase: true);
        $service = $this->service();
        $first = $service->markPending($fixture['order'], $fixture['treasury'], 'Misma razón');
        $before = $this->snapshot($fixture);

        $second = $service->markPending($fixture['order']->fresh(), $fixture['treasury']->fresh(), 'Misma razón');

        $this->assertSame((int) $first->id, (int) $second->id);
        $this->assertSnapshotUnchanged($before, $this->snapshot($fixture));
        $this->assertSame(1, PaymentRefund::where('original_payment_transaction_id', $fixture['transaction']->id)->count());
        $this->assertSame(1, PaymentSubmissionHistory::where('payment_submission_id', $fixture['submission']->id)
            ->where('event', PaymentSubmissionHistory::REFUND_MARKED_PENDING)->count());
    }

    /** @dataProvider invalidReasons */
    public function test_rejects_an_invalid_reason_without_writes(string $reason): void
    {
        $fixture = $this->fixture();
        $before = $this->snapshot($fixture);

        $this->expectException(PaymentRefundConflictException::class);
        try {
            $this->service()->markPending($fixture['order'], $fixture['treasury'], $reason);
        } finally {
            $this->assertSnapshotUnchanged($before, $this->snapshot($fixture));
        }
    }

    public static function invalidReasons(): array
    {
        return [
            'vacío' => ['   '],
            'supera límite físico' => [str_repeat('x', 1001)],
        ];
    }

    public function test_confidential_snapshot_digest_is_canonical_strict_and_has_a_safe_failure_message(): void
    {
        $first = $this->confidentialDigest([
            'object' => ['beta' => 2, 'alpha' => 1],
            'list' => ['first', 'second'],
        ]);
        $reorderedObject = $this->confidentialDigest([
            'list' => ['first', 'second'],
            'object' => ['alpha' => 1, 'beta' => 2],
        ]);
        $changedValue = $this->confidentialDigest([
            'object' => ['alpha' => 1, 'beta' => 3],
            'list' => ['first', 'second'],
        ]);
        $changedType = $this->confidentialDigest([
            'object' => ['alpha' => '1', 'beta' => 2],
            'list' => ['first', 'second'],
        ]);
        $reorderedList = $this->confidentialDigest([
            'object' => ['alpha' => 1, 'beta' => 2],
            'list' => ['second', 'first'],
        ]);

        $this->assertTrue(hash_equals($first, $reorderedObject), 'La canonicalización confidencial debe ignorar el orden de claves de objetos.');
        $this->assertFalse(hash_equals($first, $changedValue), 'La canonicalización confidencial debe detectar cambios de valor.');
        $this->assertFalse(hash_equals($first, $changedType), 'La canonicalización confidencial debe conservar tipos escalares.');
        $this->assertFalse(hash_equals($first, $reorderedList), 'La canonicalización confidencial debe conservar el orden de listas.');
        $this->assertSame(
            'El estado confidencial cambió inesperadamente.',
            $this->confidentialSnapshotFailureMessage(),
            'El helper debe construir únicamente un mensaje constante y seguro.'
        );
    }

    public function test_trims_the_reason_without_using_it_as_the_idempotency_key(): void
    {
        $fixture = $this->fixture();

        $refund = $this->service()->markPending($fixture['order'], $fixture['treasury'], '  Razón segura  ');

        $this->assertSame('Razón segura', $refund->reason);
        $this->assertFalse(hash_equals($refund->reason, $refund->idempotency_key), 'Refund reason must not be used as its idempotency key.');
    }

    /** @dataProvider invalidActorStates */
    public function test_rejects_an_invalid_actor_without_writes(string $role, bool $active): void
    {
        $fixture = $this->fixture();
        $actor = User::factory()->create([
            'role' => $role,
            'is_active' => $active,
            'email' => $this->email('invalid-actor'),
        ]);
        $before = $this->snapshot($fixture);

        $this->expectException(PaymentRefundConflictException::class);
        try {
            $this->service()->markPending($fixture['order'], $actor, 'Solicitud manual');
        } finally {
            $this->assertSnapshotUnchanged($before, $this->snapshot($fixture));
        }
    }

    public static function invalidActorStates(): array
    {
        return [
            'customer activo' => ['customer', true],
            'treasury inactivo' => ['treasury', false],
        ];
    }

    /** @dataProvider invalidOrderStates */
    public function test_rejects_ineligible_order_states_without_repair(array $changes): void
    {
        $fixture = $this->fixture();
        $fixture['order']->forceFill($changes)->save();
        $before = $this->snapshot($fixture);

        $this->expectException(PaymentRefundConflictException::class);
        try {
            $this->service()->markPending($fixture['order'], $fixture['treasury'], 'Solicitud manual');
        } finally {
            $this->assertSnapshotUnchanged($before, $this->snapshot($fixture));
        }
    }

    public static function invalidOrderStates(): array
    {
        return [
            'pedido no cancelado' => [['status' => 'pending']],
            'pago no aprobado' => [['payment_status' => 'pending']],
            'pago ya reembolsado' => [['payment_status' => 'refunded']],
            'refund pending parcial' => [['payment_status' => 'refund_pending']],
            'sin fecha de pago' => [['paid_at' => null]],
            'entregado' => [['delivered_at' => '2026-01-01 10:00:00']],
            'recogido' => [['picked_up_at' => '2026-01-01 10:00:00']],
            'tracking incompatible' => [['tracking_status' => 'pending']],
            'fulfillment incompatible' => [['fulfillment_status' => Order::FULFILLMENT_READY]],
        ];
    }

    public function test_rejects_an_absent_original_payment_without_writes(): void
    {
        $fixture = $this->fixture();
        DB::table('payment_transactions')->where('id', $fixture['transaction']->id)->delete();
        $before = $this->snapshot($fixture);

        $this->expectException(PaymentRefundConflictException::class);
        try {
            $this->service()->markPending($fixture['order'], $fixture['treasury'], 'Solicitud manual');
        } finally {
            $this->assertSnapshotUnchanged($before, $this->snapshot($fixture));
        }
    }

    public function test_rejects_duplicate_approved_payments_without_writes(): void
    {
        $fixture = $this->fixture();
        PaymentTransaction::forceCreate([
            'order_id' => $fixture['order']->id,
            'payment_method' => $fixture['order']->payment_method,
            'transaction_type' => PaymentTransaction::PAYMENT,
            'status' => PaymentTransaction::APPROVED,
            'amount' => '50.00',
            'currency' => 'PEN',
            'idempotency_key' => 'duplicate-payment-'.Str::lower(Str::random(12)),
            'approved_scope_key' => null,
            'confirmed_by' => $fixture['treasury']->id,
            'confirmed_at' => now(),
        ]);
        $before = $this->snapshot($fixture);

        $this->expectException(PaymentRefundConflictException::class);
        try {
            $this->service()->markPending($fixture['order'], $fixture['treasury'], 'Solicitud manual');
        } finally {
            $this->assertSnapshotUnchanged($before, $this->snapshot($fixture));
        }
    }

    public function test_rejects_a_payment_linked_to_a_submission_from_another_order(): void
    {
        $fixture = $this->fixture();
        $other = $this->fixture(withSubmission: true);
        DB::table('payment_transactions')->where('id', $other['transaction']->id)
            ->update(['payment_submission_id' => null]);
        DB::table('payment_transactions')->where('id', $fixture['transaction']->id)
            ->update(['payment_submission_id' => $other['submission']->id]);
        $before = $this->snapshot($fixture);

        $this->expectException(PaymentRefundConflictException::class);
        try {
            $this->service()->markPending($fixture['order'], $fixture['treasury'], 'Solicitud manual');
        } finally {
            $this->assertSnapshotUnchanged($before, $this->snapshot($fixture));
        }
    }

    /** @dataProvider invalidPaymentMutations */
    public function test_rejects_missing_or_incompatible_original_payment(string $field, mixed $value): void
    {
        $fixture = $this->fixture();
        $fixture['transaction']->forceFill([$field => $value])->saveQuietly();
        $before = $this->snapshot($fixture);

        $this->expectException(PaymentRefundConflictException::class);
        try {
            $this->service()->markPending($fixture['order'], $fixture['treasury'], 'Solicitud manual');
        } finally {
            $this->assertSnapshotUnchanged($before, $this->snapshot($fixture));
        }
    }

    public static function invalidPaymentMutations(): array
    {
        return [
            'tipo refund' => ['transaction_type', PaymentTransaction::REFUND],
            'estado received' => ['status', PaymentTransaction::RECEIVED],
            'importe distinto' => ['amount', '49.99'],
            'moneda distinta' => ['currency', 'USD'],
            'método distinto' => ['payment_method', 'otro'],
            'sin confirmación' => ['confirmed_at', null],
            'scope incorrecto' => ['approved_scope_key', null],
        ];
    }

    /** @dataProvider reservationStructureCorruptions */
    public function test_rejects_crossed_incomplete_or_ambiguous_reservation_evidence(string $corruption): void
    {
        $fixture = $this->fixture();
        $other = $this->fixture();
        if ($corruption === 'foreign item') {
            DB::table('inventory_reservations')->where('id', $other['reservation']->id)->delete();
        }

        match ($corruption) {
            'foreign order' => DB::table('inventory_reservations')->where('id', $fixture['reservation']->id)->update(['order_id' => $other['order']->id]),
            'foreign item' => DB::table('inventory_reservations')->where('id', $fixture['reservation']->id)->update(['order_item_id' => $other['item']->id]),
            'foreign product' => DB::table('inventory_reservations')->where('id', $fixture['reservation']->id)->update(['product_id' => $other['product']->id]),
            'foreign warehouse' => DB::table('inventory_reservations')->where('id', $fixture['reservation']->id)->update(['warehouse_id' => $other['warehouse']->id]),
            'wrong quantity' => DB::table('inventory_reservations')->where('id', $fixture['reservation']->id)->update(['quantity' => 1]),
            'sequence gap' => DB::table('inventory_reservations')->where('id', $fixture['reservation']->id)->update(['reservation_sequence' => 2]),
            'second consumed' => InventoryReservation::forceCreate(array_merge(
                $fixture['reservation']->getAttributes(),
                [
                    'id' => null,
                    'reservation_sequence' => 2,
                    'idempotency_key' => 'refund-second-consumed-'.Str::lower(Str::random(10)),
                ]
            )),
        };
        $before = $this->snapshot($fixture);

        $this->expectException(PaymentRefundConflictException::class);
        try {
            $this->service()->markPending($fixture['order'], $fixture['treasury'], 'Solicitud manual');
        } finally {
            $this->assertSnapshotUnchanged($before, $this->snapshot($fixture));
        }
    }

    public static function reservationStructureCorruptions(): array
    {
        return [
            'order ajeno' => ['foreign order'],
            'item ajeno' => ['foreign item'],
            'producto cruzado' => ['foreign product'],
            'almacén cruzado' => ['foreign warehouse'],
            'cantidad distinta' => ['wrong quantity'],
            'secuencia incompleta' => ['sequence gap'],
            'segunda consumida' => ['second consumed'],
        ];
    }

    /** @dataProvider reassignmentEvidenceCorruptions */
    public function test_rejects_an_incomplete_or_incompatible_b2_final_state(string $corruption): void
    {
        $fixture = $this->fixture(withSubmission: true, withResolutionCase: true);

        match ($corruption) {
            'missing opening history' => DB::table('payment_resolution_histories')->where('payment_resolution_case_id', $fixture['case']->id)->where('event', 'opened')->delete(),
            'missing resolved timestamp' => DB::table('payment_resolution_cases')->where('id', $fixture['case']->id)->update(['resolved_at' => null]),
            'crossed actor' => DB::table('payment_resolution_cases')->where('id', $fixture['case']->id)->update(['resolved_by' => $fixture['customer']->id]),
            'wrong final metadata' => DB::table('payment_submission_histories')->where('payment_submission_id', $fixture['submission']->id)->where('event', PaymentSubmissionHistory::REASSIGNMENT_APPROVED)->update(['safe_metadata' => json_encode([
                'resolution_case_id' => $fixture['case']->id,
                'reservation_count' => 1,
                'reopened_after_expiration' => false,
                'amount' => '50.00',
            ], JSON_THROW_ON_ERROR)]),
            'crossed transaction' => DB::table('payment_resolution_cases')->where('id', $fixture['case']->id)->update(['received_payment_transaction_id' => $this->fixture()['transaction']->id]),
        };
        $before = $this->snapshot($fixture);

        $this->expectException(PaymentRefundConflictException::class);
        try {
            $this->service()->markPending($fixture['order'], $fixture['treasury'], 'Solicitud manual');
        } finally {
            $this->assertSnapshotUnchanged($before, $this->snapshot($fixture));
        }
    }

    public static function reassignmentEvidenceCorruptions(): array
    {
        return [
            'sin opened' => ['missing opening history'],
            'sin resolved at' => ['missing resolved timestamp'],
            'actor cruzado' => ['crossed actor'],
            'metadata final extra' => ['wrong final metadata'],
            'transacción cruzada' => ['crossed transaction'],
        ];
    }

    /** @dataProvider inventoryCorruptions */
    public function test_rejects_uncompensated_or_corrupt_inventory_without_writes(string $corruption): void
    {
        $fixture = $this->fixture();
        if ($corruption === 'negative inventory' && DB::connection()->getDriverName() === 'mysql') {
            $before = $this->snapshot($fixture);
            try {
                DB::table('warehouse_inventories')->where('id', $fixture['inventory']->id)->update(['quantity' => -1]);
                $this->fail('MySQL must reject a negative UNSIGNED inventory balance.');
            } catch (QueryException $exception) {
                $this->assertSame('22003', (string) ($exception->errorInfo[0] ?? ''));
                $this->assertSame(1264, (int) ($exception->errorInfo[1] ?? 0));
            }
            $this->assertSnapshotUnchanged($before, $this->snapshot($fixture));
            $this->assertTrue(DB::table('warehouse_inventories')->where('id', $fixture['inventory']->id)->exists());

            return;
        }

        match ($corruption) {
            'active reservation' => $fixture['reservation']->forceFill(['status' => InventoryReservation::ACTIVE, 'consumed_at' => null])->save(),
            'missing sale' => DB::table('inventory_movements')->where('id', $fixture['sale']->id)->delete(),
            'missing return' => DB::table('inventory_movements')->where('id', $fixture['return']->id)->delete(),
            'inventory mismatch' => $fixture['inventory']->update(['quantity' => 11]),
            'reserved mismatch' => $fixture['inventory']->update(['reserved_quantity' => 1]),
            'product mismatch' => $fixture['product']->forceFill(['stock' => 11])->save(),
            'duplicate return' => InventoryMovement::create([
                'warehouse_id' => $fixture['warehouse']->id,
                'product_id' => $fixture['product']->id,
                'user_id' => $fixture['treasury']->id,
                'type' => InventoryMovement::CANCELLATION_RETURN,
                'quantity' => 2,
                'quantity_before' => 10,
                'quantity_after' => 12,
                'reason' => 'Duplicado',
                'reference_type' => 'order',
                'reference_id' => (string) $fixture['order']->id,
                'idempotency_key' => 'duplicate-return-'.Str::lower(Str::random(10)),
                'created_at' => now(),
            ]),
            'negative inventory' => DB::table('warehouse_inventories')->where('id', $fixture['inventory']->id)->update(['quantity' => -1]),
        };
        $before = $this->snapshot($fixture);

        $this->expectException(PaymentRefundConflictException::class);
        try {
            $this->service()->markPending($fixture['order'], $fixture['treasury'], 'Solicitud manual');
        } finally {
            $this->assertSnapshotUnchanged($before, $this->snapshot($fixture));
        }
    }

    public static function inventoryCorruptions(): array
    {
        return [
            'reserva activa' => ['active reservation'],
            'venta ausente' => ['missing sale'],
            'reposición ausente' => ['missing return'],
            'saldo físico incoherente' => ['inventory mismatch'],
            'saldo reservado incoherente' => ['reserved mismatch'],
            'stock agregado incoherente' => ['product mismatch'],
            'reposición duplicada' => ['duplicate return'],
            'saldo negativo' => ['negative inventory'],
        ];
    }

    public function test_accepts_a_released_reservation_without_creating_or_requiring_a_return(): void
    {
        $fixture = $this->fixture();
        $fixture['reservation']->forceFill([
            'status' => InventoryReservation::RELEASED,
            'consumed_at' => null,
            'released_at' => now()->subHour(),
        ])->save();
        DB::table('inventory_movements')->whereIn('id', [$fixture['sale']->id, $fixture['return']->id])->delete();
        $inventoryBefore = $this->inventorySnapshot($fixture);

        $refund = $this->service()->markPending($fixture['order'], $fixture['treasury'], 'Reserva liberada');

        $this->assertSame(PaymentRefund::PENDING, $refund->status);
        $this->assertSnapshotUnchanged($inventoryBefore, $this->inventorySnapshot($fixture));
        $this->assertSame(0, InventoryMovement::where('reference_type', 'order')->where('reference_id', (string) $fixture['order']->id)->count());
    }

    public function test_an_incoherent_retry_is_rejected_without_repair(): void
    {
        $fixture = $this->fixture(withSubmission: true);
        $refund = $this->service()->markPending($fixture['order'], $fixture['treasury'], 'Razón original');
        $before = $this->snapshot($fixture);

        $this->expectException(PaymentRefundConflictException::class);
        try {
            $this->service()->markPending($fixture['order']->fresh(), $fixture['treasury']->fresh(), 'Razón diferente');
        } finally {
            $this->assertSnapshotUnchanged($before, $this->snapshot($fixture));
            $this->assertSame('Razón original', $refund->fresh()->reason);
        }
    }

    public function test_rejects_a_premature_refund_history_without_creating_a_duplicate(): void
    {
        $fixture = $this->fixture(withSubmission: true);
        PaymentSubmissionHistory::create([
            'payment_submission_id' => $fixture['submission']->id,
            'event' => PaymentSubmissionHistory::REFUND_MARKED_PENDING,
            'from_status' => PaymentSubmission::APPROVED,
            'to_status' => PaymentSubmission::REFUND_PENDING,
            'actor_id' => $fixture['treasury']->id,
            'reason' => 'Prematuro',
            'safe_metadata' => ['amount' => '50.00', 'currency' => 'PEN'],
            'occurred_at' => now(),
        ]);
        $before = $this->snapshot($fixture);

        $this->expectException(PaymentRefundConflictException::class);
        try {
            $this->service()->markPending($fixture['order'], $fixture['treasury'], 'Solicitud manual');
        } finally {
            $this->assertSnapshotUnchanged($before, $this->snapshot($fixture));
            $this->assertSame(1, PaymentSubmissionHistory::where('payment_submission_id', $fixture['submission']->id)
                ->where('event', PaymentSubmissionHistory::REFUND_MARKED_PENDING)->count());
        }
    }

    /** @dataProvider rollbackStages */
    public function test_rolls_back_every_write_stage(string $stage): void
    {
        $fixture = $this->fixture(withSubmission: true, withResolutionCase: true);
        $before = $this->snapshot($fixture);
        $service = $this->instrumentedService($stage, new RuntimeException('induced rollback'));

        $this->expectException(RuntimeException::class);
        try {
            $service->markPending($fixture['order'], $fixture['treasury'], 'Solicitud manual');
        } finally {
            $this->assertSnapshotUnchanged($before, $this->snapshot($fixture));
        }
    }

    public static function rollbackStages(): array
    {
        return [
            'tras refund' => ['refund'],
            'tras order' => ['order'],
            'tras historial' => ['history'],
        ];
    }

    public function test_non_concurrent_query_exception_propagates_without_retry_or_writes(): void
    {
        $fixture = $this->fixture();
        $before = $this->snapshot($fixture);
        $exception = new QueryException('refund probe', [], new PDOException('synthetic syntax failure', 9999));
        $service = $this->instrumentedService('financial', $exception);

        try {
            $service->markPending($fixture['order'], $fixture['treasury'], 'Solicitud manual');
            $this->fail('The non-concurrent query exception must propagate.');
        } catch (QueryException $caught) {
            $this->assertTrue($exception === $caught, 'La excepción no concurrente original debe propagarse sin exponer su contenido.');
        }

        $this->assertSame(1, $service->attempts);
        $this->assertSnapshotUnchanged($before, $this->snapshot($fixture));
    }

    public function test_a_recognized_concurrency_error_retries_the_complete_transaction_once(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            $this->markTestSkipped('Laravel no reintenta un deadlock sintético dentro de la transacción exterior de RefreshDatabase en MySQL.');
        }

        $fixture = $this->fixture();
        $before = $this->inventorySnapshot($fixture);
        $service = $this->instrumentedService('refund', $this->recognizedConcurrencyException(), false);

        $refund = $service->markPending($fixture['order'], $fixture['treasury'], 'Solicitud manual');

        $this->assertSame(2, $service->attempts);
        $this->assertCount(2, $service->hydrations);
        $this->assertFalse($service->hydrations[0]['order'] === $service->hydrations[1]['order'], 'El retry debe rehidratar el pedido.');
        $this->assertFalse($service->hydrations[0]['transaction'] === $service->hydrations[1]['transaction'], 'El retry debe rehidratar la transacción.');
        $this->assertFalse($service->hydrations[0]['actor'] === $service->hydrations[1]['actor'], 'El retry debe rehidratar el actor.');
        $this->assertSame(PaymentRefund::PENDING, $refund->status);
        $this->assertSame(1, PaymentRefund::where('original_payment_transaction_id', $fixture['transaction']->id)->count());
        $this->assertSnapshotUnchanged($before, $this->inventorySnapshot($fixture));
    }

    public function test_a_persistent_concurrency_error_is_propagated_after_three_attempts(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            $this->markTestSkipped('Laravel no reintenta un deadlock sintético dentro de la transacción exterior de RefreshDatabase en MySQL.');
        }

        $fixture = $this->fixture();
        $before = $this->snapshot($fixture);
        $exception = $this->recognizedConcurrencyException();
        $service = $this->instrumentedService('financial', $exception, true);

        try {
            $service->markPending($fixture['order'], $fixture['treasury'], 'Solicitud manual');
            $this->fail('The exhausted concurrency error must propagate.');
        } catch (QueryException $caught) {
            $this->assertTrue($exception === $caught, 'El deadlock agotado original debe propagarse sin exponer su contenido.');
        }

        $this->assertSame(3, $service->attempts);
        $this->assertSnapshotUnchanged($before, $this->snapshot($fixture));
    }

    public function test_a_partial_unique_collision_is_not_repaired(): void
    {
        $fixture = $this->fixture();
        $before = $this->snapshot($fixture);
        $service = $this->instrumentedService('refund', $this->uniqueException());

        $this->expectException(PaymentRefundConflictException::class);
        try {
            $service->markPending($fixture['order'], $fixture['treasury'], 'Solicitud manual');
        } finally {
            $this->assertSnapshotUnchanged($before, $this->snapshot($fixture));
        }
    }

    private function service(): TreasuryPaymentRefundService
    {
        return app(TreasuryPaymentRefundService::class);
    }

    private function instrumentedService(string $stage, \Throwable $exception, bool $always = true): TreasuryPaymentRefundService
    {
        return new class(app(InventoryBalanceLockService::class), app(TreasuryPaymentService::class), $stage, $exception, $always) extends TreasuryPaymentRefundService
        {
            public int $attempts = 0;

            /** @var array<int, array{order: Order, transaction: PaymentTransaction, actor: User}> */
            public array $hydrations = [];

            public function __construct(InventoryBalanceLockService $locks, TreasuryPaymentService $treasury, private string $stage, private \Throwable $exception, private bool $always)
            {
                parent::__construct($locks, $treasury);
            }

            protected function afterFinancialStateLocked(array $state): void
            {
                $this->attempts++;
                $this->hydrations[] = [
                    'order' => $state['order'],
                    'transaction' => $state['originalTransaction'],
                    'actor' => $state['actor'],
                ];
                $this->throwAt('financial');
            }

            protected function afterRefundCreated(PaymentRefund $refund): void
            {
                $this->throwAt('refund');
            }

            protected function afterOrderMarkedRefundPending(Order $order): void
            {
                $this->throwAt('order');
            }

            protected function afterSubmissionHistoryCreated(PaymentSubmission $submission): void
            {
                $this->throwAt('history');
            }

            private function throwAt(string $stage): void
            {
                if ($this->stage === $stage && ($this->always || $this->attempts === 1)) {
                    throw $this->exception;
                }
            }
        };
    }

    private function recognizedConcurrencyException(): QueryException
    {
        return new QueryException('refund retry probe', [], new PDOException('database is locked', 40001));
    }

    private function uniqueException(): QueryException
    {
        $previous = new PDOException('UNIQUE constraint failed: payment_refunds.idempotency_key', 19);
        $previous->errorInfo = ['23000', 19, 'UNIQUE constraint failed: payment_refunds.idempotency_key'];

        return new QueryException('refund unique probe', [], $previous);
    }

    /** @return array<string, mixed> */
    private function fixture(string $paymentMethod = 'transferencia', bool $withSubmission = false, bool $withResolutionCase = false): array
    {
        $suffix = Str::lower(Str::random(12));
        $openedAt = now()->subHours(3);
        $resolvedAt = now()->subHours(2);
        $treasury = User::factory()->create(['role' => 'treasury', 'is_active' => true, 'email' => "treasury-{$suffix}@example.test"]);
        $customer = User::factory()->create(['role' => 'customer', 'is_active' => true, 'email' => "customer-{$suffix}@example.test"]);
        $branch = Branch::create(['code' => 'RF-'.$suffix, 'name' => 'Refund '.$suffix, 'address' => 'Test', 'is_active' => true]);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'code' => 'RW-'.$suffix, 'name' => 'Refund warehouse', 'is_active' => true]);
        $product = Product::create(['name' => 'Refund product '.$suffix, 'slug' => 'refund-product-'.$suffix, 'sku' => 'RF-'.$suffix, 'price' => '25.00', 'is_active' => true]);
        $product->forceFill(['stock' => 12])->save();
        $inventory = WarehouseInventory::create(['warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'quantity' => 12, 'reserved_quantity' => 0]);
        $order = Order::create([
            'user_id' => $customer->id,
            'status' => 'canceled',
            'total' => '50.00',
            'shipping_info' => ['address' => 'Test'],
            'payment_method' => $paymentMethod,
            'payment_status' => 'approved',
            'paid_at' => $withResolutionCase ? $resolvedAt : now()->subHour(),
            'reserved_until' => $withResolutionCase ? now()->addMinutes(30) : null,
            'tracking_status' => 'canceled',
            'fulfillment_status' => Order::FULFILLMENT_CANCELED,
        ]);
        $item = OrderItem::forceCreate(['order_id' => $order->id, 'product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'quantity' => 2, 'price' => '25.00', 'subtotal' => '50.00']);
        $reservation = InventoryReservation::forceCreate([
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 2,
            'reservation_sequence' => 1,
            'resolution_case_id' => null,
            'status' => InventoryReservation::CONSUMED,
            'expires_at' => $withResolutionCase ? now()->addMinutes(30) : now()->subDay(),
            'consumed_at' => $resolvedAt,
            'released_at' => null,
            'idempotency_key' => 'refund-reservation-'.$suffix,
            'metadata' => null,
        ]);
        $sale = InventoryMovement::create([
            'warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'user_id' => $treasury->id,
            'type' => InventoryMovement::SALE, 'quantity' => 2, 'quantity_before' => 12, 'quantity_after' => 10,
            'reason' => 'Venta', 'reference_type' => 'order', 'reference_id' => (string) $order->id,
            'idempotency_key' => 'sale-order-item-'.$item->id, 'metadata' => null, 'created_at' => now()->subHours(2),
        ]);
        $return = InventoryMovement::create([
            'warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'user_id' => $treasury->id,
            'type' => InventoryMovement::CANCELLATION_RETURN, 'quantity' => 2, 'quantity_before' => 10, 'quantity_after' => 12,
            'reason' => 'Reposición', 'reference_type' => 'order', 'reference_id' => (string) $order->id,
            'idempotency_key' => 'cancellation-order-item-'.$item->id, 'metadata' => null, 'created_at' => now()->subHour(),
        ]);
        OrderFulfillmentHistory::create([
            'order_id' => $order->id,
            'from_status' => Order::FULFILLMENT_RESERVED,
            'to_status' => Order::FULFILLMENT_CANCELED,
            'user_id' => $treasury->id,
            'observation' => 'Cancelación manual',
            'metadata' => ['inventory_was_consumed' => true],
            'idempotency_key' => 'fulfillment-order-'.$order->id.'-canceled',
            'created_at' => now()->subHour(),
        ]);

        $submission = null;
        if ($withSubmission) {
            $submission = PaymentSubmission::create([
                'order_id' => $order->id,
                'channel' => 'bank_transfer',
                'status' => PaymentSubmission::APPROVED,
                'expected_amount' => '50.00',
                'currency' => 'PEN',
                'operation_number' => 'OP-'.$suffix,
                'normalized_operation_number' => 'OP'.$suffix,
                'duplicate_fingerprint' => hash('sha256', 'refund-'.$suffix),
                'declared_paid_at' => now()->subHours(3),
                'receiving_account_snapshot' => ['channel' => 'bank_transfer'],
                'submitted_by' => $customer->id,
                'submitted_at' => now()->subHours(3),
                'reviewed_by' => $treasury->id,
                'reviewed_at' => $resolvedAt,
                'decision_reason' => $withResolutionCase ? 'stock_reassignment_approved' : 'Aprobado',
                'idempotency_key' => 'refund-submission-'.$suffix,
            ]);
        }

        $transaction = PaymentTransaction::forceCreate([
            'order_id' => $order->id,
            'payment_submission_id' => $submission?->id,
            'gateway' => $paymentMethod === 'mercadopago' ? 'mercadopago' : 'manual',
            'payment_method' => $paymentMethod,
            'collection_method' => $withResolutionCase ? $submission->channel : null,
            'transaction_type' => PaymentTransaction::PAYMENT,
            'status' => PaymentTransaction::APPROVED,
            'amount' => '50.00',
            'currency' => 'PEN',
            'idempotency_key' => $withResolutionCase ? 'treasury-received-submission-'.$submission->id : 'refund-payment-'.$suffix,
            'approved_scope_key' => PaymentTransaction::approvedScopeKeyForOrder($order->id),
            'confirmed_by' => $treasury->id,
            'confirmed_at' => $withResolutionCase ? $openedAt : $resolvedAt,
            'metadata' => $withResolutionCase ? ['source' => 'treasury_resolution_opening'] : null,
        ]);

        $case = null;
        if ($withResolutionCase) {
            $case = PaymentResolutionCase::forceCreate([
                'payment_submission_id' => $submission->id,
                'received_payment_transaction_id' => $transaction->id,
                'type' => PaymentResolutionCase::STOCK_REASSIGNMENT,
                'status' => PaymentResolutionCase::REASSIGNED,
                'amount' => '50.00',
                'currency' => 'PEN',
                'reason' => 'stock_reassignment_approved',
                'funds_received_at' => $transaction->confirmed_at,
                'requested_by' => $treasury->id,
                'resolved_by' => $treasury->id,
                'requested_at' => $openedAt,
                'resolved_at' => $resolvedAt,
                'idempotency_key' => 'treasury-resolution-submission-'.$submission->id,
            ]);
            $reservation->forceFill(['resolution_case_id' => $case->id])->save();

            PaymentSubmissionHistory::create([
                'payment_submission_id' => $submission->id,
                'event' => 'resolution_opened',
                'from_status' => PaymentSubmission::PENDING_REVIEW,
                'to_status' => PaymentSubmission::MANUAL_RESOLUTION_REQUIRED,
                'actor_id' => $treasury->id,
                'reason' => 'payment_received_reservation_unavailable',
                'safe_metadata' => ['channel' => $submission->channel, 'expected_amount' => '50.00', 'currency' => 'PEN'],
                'occurred_at' => $openedAt,
            ]);
            PaymentResolutionHistory::create([
                'payment_resolution_case_id' => $case->id,
                'event' => 'opened',
                'from_status' => null,
                'to_status' => PaymentResolutionCase::OPEN,
                'actor_id' => $treasury->id,
                'reason' => 'payment_received_reservation_unavailable',
                'safe_metadata' => ['currency' => 'PEN', 'amount' => '50.00'],
                'occurred_at' => $openedAt,
            ]);
            $finalMetadata = ['resolution_case_id' => $case->id, 'reservation_count' => 1, 'reopened_after_expiration' => false];
            PaymentSubmissionHistory::create([
                'payment_submission_id' => $submission->id,
                'event' => PaymentSubmissionHistory::REASSIGNMENT_APPROVED,
                'from_status' => PaymentSubmission::MANUAL_RESOLUTION_REQUIRED,
                'to_status' => PaymentSubmission::APPROVED,
                'actor_id' => $treasury->id,
                'reason' => 'stock_reassignment_approved',
                'safe_metadata' => $finalMetadata,
                'occurred_at' => $resolvedAt,
            ]);
            PaymentResolutionHistory::create([
                'payment_resolution_case_id' => $case->id,
                'event' => 'reassigned',
                'from_status' => PaymentResolutionCase::OPEN,
                'to_status' => PaymentResolutionCase::REASSIGNED,
                'actor_id' => $treasury->id,
                'reason' => 'stock_reassignment_approved',
                'safe_metadata' => $finalMetadata,
                'occurred_at' => $resolvedAt,
            ]);
        }

        return compact('treasury', 'customer', 'branch', 'warehouse', 'product', 'inventory', 'order', 'item', 'reservation', 'sale', 'return', 'submission', 'transaction', 'case');
    }

    /** @param array<string, mixed> $fixture */
    private function inventorySnapshot(array $fixture): array
    {
        return $this->buildSnapshot($fixture, true);
    }

    /** @param array<string, mixed> $fixture */
    private function snapshot(array $fixture): array
    {
        return $this->buildSnapshot($fixture, false);
    }

    /** @param array<string, mixed> $fixture */
    private function buildSnapshot(array $fixture, bool $inventoryOnly): array
    {
        $orderId = (int) $fixture['order']->id;
        $itemIds = DB::table('order_items')->where('order_id', $orderId)->pluck('id');
        $submissionIds = DB::table('payment_submissions')->where('order_id', $orderId)->pluck('id');
        $transactionIds = DB::table('payment_transactions')
            ->where('order_id', $orderId)
            ->orWhereIn('payment_submission_id', $submissionIds)
            ->pluck('id');
        $caseIds = DB::table('payment_resolution_cases')
            ->whereIn('payment_submission_id', $submissionIds)
            ->orWhereIn('received_payment_transaction_id', $transactionIds)
            ->pluck('id');
        $transactionIds = $transactionIds
            ->merge(DB::table('payment_resolution_cases')->whereIn('id', $caseIds)->pluck('received_payment_transaction_id'))
            ->filter()->unique()->values();
        $reservationRows = DB::table('inventory_reservations')
            ->where('order_id', $orderId)
            ->orWhereIn('order_item_id', $itemIds)
            ->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        $warehouseIds = collect($reservationRows)->pluck('warehouse_id')
            ->merge(DB::table('order_items')->whereIn('id', $itemIds)->pluck('warehouse_id'))->unique();
        $productIds = collect($reservationRows)->pluck('product_id')
            ->merge(DB::table('order_items')->whereIn('id', $itemIds)->pluck('product_id'))->unique();
        $branchIds = DB::table('warehouses')->whereIn('id', $warehouseIds)->pluck('branch_id')->filter()->unique();
        $actorIds = collect([$fixture['treasury']->id ?? null, $fixture['customer']->id ?? null])
            ->merge(DB::table('orders')->where('id', $orderId)->pluck('user_id'))
            ->merge(DB::table('payment_transactions')->whereIn('id', $transactionIds)->pluck('confirmed_by'))
            ->merge(DB::table('payment_submissions')->whereIn('id', $submissionIds)->pluck('submitted_by'))
            ->merge(DB::table('payment_submissions')->whereIn('id', $submissionIds)->pluck('reviewed_by'))
            ->merge(DB::table('payment_submission_histories')->whereIn('payment_submission_id', $submissionIds)->pluck('actor_id'))
            ->merge(DB::table('payment_resolution_cases')->whereIn('id', $caseIds)->pluck('requested_by'))
            ->merge(DB::table('payment_resolution_cases')->whereIn('id', $caseIds)->pluck('resolved_by'))
            ->merge(DB::table('payment_resolution_histories')->whereIn('payment_resolution_case_id', $caseIds)->pluck('actor_id'))
            ->merge(DB::table('inventory_movements')->where('reference_type', 'order')->where('reference_id', (string) $orderId)->pluck('user_id'))
            ->merge(DB::table('payment_refunds')->where(function ($query) use ($transactionIds, $caseIds) {
                $query->whereIn('original_payment_transaction_id', $transactionIds)
                    ->orWhereIn('refund_payment_transaction_id', $transactionIds)
                    ->orWhereIn('payment_resolution_case_id', $caseIds);
            })->pluck('requested_by'))
            ->merge(DB::table('payment_refunds')->where(function ($query) use ($transactionIds, $caseIds) {
                $query->whereIn('original_payment_transaction_id', $transactionIds)
                    ->orWhereIn('refund_payment_transaction_id', $transactionIds)
                    ->orWhereIn('payment_resolution_case_id', $caseIds);
            })->pluck('completed_by'))
            ->filter()->map(fn ($id) => (int) $id)->unique()->values();

        $rows = [
            'users' => $this->rows('users', fn ($query) => $query->whereIn('id', $actorIds)),
            'branches' => $this->rows('branches', fn ($query) => $query->whereIn('id', $branchIds)),
            'warehouses' => $this->rows('warehouses', fn ($query) => $query->whereIn('id', $warehouseIds)),
            'items' => $this->rows('order_items', fn ($query) => $query->where('order_id', $orderId)),
            'reservations' => $reservationRows,
            'warehouse_inventories' => $this->rows('warehouse_inventories', fn ($query) => $query->whereIn('warehouse_id', $warehouseIds)->whereIn('product_id', $productIds)),
            'products' => $this->rows('products', fn ($query) => $query->whereIn('id', $productIds)),
            'inventory_movements' => $this->rows('inventory_movements', fn ($query) => $query->where('reference_type', 'order')->where('reference_id', (string) $orderId)),
        ];

        if (! $inventoryOnly) {
            $rows += [
                'orders' => $this->rows('orders', fn ($query) => $query->where('id', $orderId)),
                'payment_submissions' => $this->rows('payment_submissions', fn ($query) => $query->whereIn('id', $submissionIds)),
                'payment_transactions' => $this->rows('payment_transactions', fn ($query) => $query->whereIn('id', $transactionIds)),
                'payment_resolution_cases' => $this->rows('payment_resolution_cases', fn ($query) => $query->whereIn('id', $caseIds)),
                'payment_submission_histories' => $this->rows('payment_submission_histories', fn ($query) => $query->whereIn('payment_submission_id', $submissionIds)),
                'payment_resolution_histories' => $this->rows('payment_resolution_histories', fn ($query) => $query->whereIn('payment_resolution_case_id', $caseIds)),
                'payment_refunds' => $this->rows('payment_refunds', fn ($query) => $query
                    ->whereIn('original_payment_transaction_id', $transactionIds)
                    ->orWhereIn('refund_payment_transaction_id', $transactionIds)
                    ->orWhereIn('payment_resolution_case_id', $caseIds)),
                'order_fulfillment_histories' => $this->rows('order_fulfillment_histories', fn ($query) => $query->where('order_id', $orderId)),
                'order_handling_processes' => $this->rowsIfPresent('order_handling_processes', fn ($query) => $query->where('order_id', $orderId)),
                'order_handling_items' => $this->rowsIfPresent('order_handling_items', fn ($query) => $query->whereIn('order_item_id', $itemIds)),
                'order_handling_incidents' => $this->rowsIfPresent('order_handling_incidents', fn ($query) => $query->where('order_id', $orderId)),
                'order_handling_histories' => $this->rowsIfPresent('order_handling_histories', fn ($query) => $query->where('order_id', $orderId)),
                'order_deliveries' => $this->rowsIfPresent('order_deliveries', fn ($query) => $query->where('order_id', $orderId)),
                'order_delivery_attempts' => $this->rowsIfPresent('order_delivery_attempts', fn ($query) => $query->whereIn('order_delivery_id', DB::table('order_deliveries')->where('order_id', $orderId)->pluck('id'))),
                'order_delivery_histories' => $this->rowsIfPresent('order_delivery_histories', fn ($query) => $query->where('order_id', $orderId)),
            ];
        }

        $public = [];
        $confidential = [];
        foreach ($rows as $table => $tableRows) {
            foreach ($tableRows as $index => $row) {
                foreach ($row as $key => $value) {
                    if ($this->isConfidentialSnapshotField($key)) {
                        $confidential[$table][$index][$key] = $value;
                        unset($row[$key]);
                    }
                }
                $public[$table][$index] = $this->canonicalize($row);
            }
        }

        return $this->snapshotParts($public, $confidential);
    }

    private function rows(string $table, callable $scope): array
    {
        $query = DB::table($table);
        $scope($query);

        return $query->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
    }

    private function rowsIfPresent(string $table, callable $scope): array
    {
        return \Illuminate\Support\Facades\Schema::hasTable($table) ? $this->rows($table, $scope) : [];
    }

    private function isConfidentialSnapshotField(string $field): bool
    {
        $normalized = strtolower($field);

        return in_array($normalized, [
            'idempotency_key',
            'approved_scope_key',
            'duplicate_fingerprint',
            'operation_number',
            'normalized_operation_number',
            'external_reference',
            'provider_payment_id',
            'payment_preference_id',
            'manual_reference',
            'password',
            'remember_token',
            'email',
            'phone',
            'metadata',
            'safe_metadata',
            'receiving_account_snapshot',
        ], true)
            || str_contains($normalized, 'reference')
            || str_contains($normalized, 'operation')
            || str_contains($normalized, 'fingerprint')
            || str_contains($normalized, 'scope')
            || str_ends_with($normalized, '_key')
            || str_ends_with($normalized, '_reference')
            || str_ends_with($normalized, '_token')
            || str_ends_with($normalized, '_secret');
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn ($item) => $this->canonicalize($item), $value);
        }

        ksort($value);

        return array_map(fn ($item) => $this->canonicalize($item), $value);
    }

    private function assertSnapshotUnchanged(array $expected, array $actual): void
    {
        $this->assertSame($expected['public'], $actual['public'], 'The fixture public snapshot changed unexpectedly.');
        $this->assertTrue(
            hash_equals($expected['confidential_digest'], $actual['confidential_digest']),
            $this->confidentialSnapshotFailureMessage()
        );
    }

    /** @param array<string, mixed> $attributes */
    private function financialEntitySnapshot(array $attributes): array
    {
        $public = [];
        $confidential = [];
        foreach ($attributes as $field => $value) {
            if ($this->isConfidentialSnapshotField($field)) {
                $confidential[$field] = $value;
            } else {
                $public[$field] = $value;
            }
        }

        return $this->snapshotParts($public, $confidential);
    }

    private function snapshotParts(array $public, array $confidential): array
    {
        return [
            'public' => $this->canonicalize($public),
            'confidential_digest' => $this->confidentialDigest($confidential),
        ];
    }

    private function confidentialDigest(array $confidential): string
    {
        return hash('sha256', json_encode(
            $this->canonicalize($confidential),
            JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION
        ));
    }

    private function confidentialSnapshotFailureMessage(): string
    {
        return 'El estado confidencial cambió inesperadamente.';
    }

    /** @param array<string, mixed> $value */
    private function sortedKeys(array $value): array
    {
        $keys = array_keys($value);
        sort($keys);

        return $keys;
    }

    private function email(string $prefix): string
    {
        return $prefix.'-'.Str::lower(Str::random(12)).'@example.test';
    }
}
