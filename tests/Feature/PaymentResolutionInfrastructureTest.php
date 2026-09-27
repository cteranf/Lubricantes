<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\InventoryReservation;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentRefund;
use App\Models\PaymentResolutionCase;
use App\Models\PaymentResolutionHistory;
use App\Models\PaymentSubmission;
use App\Models\PaymentTransaction;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use LogicException;
use Tests\TestCase;

class PaymentResolutionInfrastructureTest extends TestCase
{
    use RefreshDatabase;

    public function test_historical_reservations_default_to_sequence_one_and_versioned_reservations_are_allowed(): void
    {
        [$order, $item] = $this->orderWithItem();
        $first = $this->reservation($order, $item, null, 'reservation-first');
        $second = $this->reservation($order, $item, 2, 'reservation-second');

        $this->assertSame(1, $first->fresh()->reservation_sequence);
        $this->assertSame(2, $second->reservation_sequence);
        $this->assertSame(2, InventoryReservation::where('order_item_id', $item->id)->count());
    }

    public function test_duplicate_reservation_sequence_and_idempotency_key_are_rejected_by_the_database(): void
    {
        [$order, $item] = $this->orderWithItem();
        $this->reservation($order, $item, 1, 'reservation-unique-one');

        try {
            $this->reservation($order, $item, 1, 'reservation-unique-two');
            $this->fail('La base permitió duplicar la secuencia de reserva del mismo ítem.');
        } catch (QueryException) {
            $this->assertSame(1, InventoryReservation::count());
        }

        [$anotherOrder, $anotherItem] = $this->orderWithItem();
        $this->expectException(QueryException::class);
        $this->reservation($anotherOrder, $anotherItem, 1, 'reservation-unique-one');
    }

    public function test_case_and_refund_uniques_allow_null_financial_links_but_block_duplicate_non_null_links(): void
    {
        $firstCase = $this->resolutionCase($this->submission($this->orderWithItem()[0], 'case-submission-one'), 'case-one');
        $secondCase = $this->resolutionCase($this->submission($this->orderWithItem()[0], 'case-submission-two'), 'case-two');
        $this->assertNull($firstCase->received_payment_transaction_id);
        $this->assertNull($secondCase->received_payment_transaction_id);

        $transaction = $this->transaction($firstCase->submission, 'received-transaction-one');
        $firstCase->forceFill(['received_payment_transaction_id' => $transaction->id])->save();

        try {
            $secondCase->forceFill(['received_payment_transaction_id' => $transaction->id])->save();
            $this->fail('La base permitió reutilizar una transacción recibida entre casos.');
        } catch (QueryException) {
            $this->assertNull($secondCase->fresh()->received_payment_transaction_id);
        }

        $firstRefund = $this->refund($firstCase, 'refund-one');
        $secondRefund = $this->refund($secondCase, 'refund-two');
        $refundTransaction = $this->transaction($firstCase->submission, 'refund-transaction-one', PaymentTransaction::REFUND, PaymentTransaction::APPROVED);
        $firstRefund->forceFill(['refund_payment_transaction_id' => $refundTransaction->id])->save();

        try {
            $secondRefund->forceFill(['refund_payment_transaction_id' => $refundTransaction->id])->save();
            $this->fail('La base permitió reutilizar una transacción de devolución.');
        } catch (QueryException) {
            $this->assertNull($secondRefund->fresh()->refund_payment_transaction_id);
        }
    }

    public function test_case_submission_and_refund_case_foreign_keys_restrict_deletion(): void
    {
        $submission = $this->submission($this->orderWithItem()[0], 'case-restrict-submission');
        $case = $this->resolutionCase($submission, 'case-restrict');
        $this->refund($case, 'refund-restrict');

        $this->expectException(QueryException::class);
        $submission->delete();
    }

    public function test_resolution_history_prevents_case_deletion_and_requires_a_real_case(): void
    {
        $case = $this->resolutionCase($this->submission($this->orderWithItem()[0], 'history-restrict-submission'), 'history-restrict');
        PaymentResolutionHistory::create([
            'payment_resolution_case_id' => $case->id,
            'event' => 'opened',
            'to_status' => PaymentResolutionCase::OPEN,
            'occurred_at' => now(),
        ]);

        try {
            DB::table('payment_resolution_cases')->where('id', $case->id)->delete();
            $this->fail('La base permitió borrar un caso que tiene auditoría de resolución.');
        } catch (QueryException) {
            $this->assertDatabaseHas('payment_resolution_cases', ['id' => $case->id]);
        }

        $this->expectException(QueryException::class);
        DB::table('payment_resolution_histories')->insert([
            'payment_resolution_case_id' => PHP_INT_MAX,
            'event' => 'opened',
            'to_status' => PaymentResolutionCase::OPEN,
            'occurred_at' => now(),
            'created_at' => now(),
        ]);
    }

    public function test_case_and_refund_uniques_and_financial_foreign_keys_are_enforced_by_the_database(): void
    {
        $submission = $this->submission($this->orderWithItem()[0], 'case-unique-submission');
        $case = $this->resolutionCase($submission, 'case-unique-one');

        try {
            $this->resolutionCase($submission, 'case-unique-two');
            $this->fail('La base permitió más de un caso para la presentación.');
        } catch (QueryException) {
            $this->assertSame(1, PaymentResolutionCase::where('payment_submission_id', $submission->id)->count());
        }

        $this->refund($case, 'refund-case-one');
        try {
            $this->refund($case, 'refund-case-two');
            $this->fail('La base permitió más de una devolución para el caso.');
        } catch (QueryException) {
            $this->assertSame(1, PaymentRefund::where('payment_resolution_case_id', $case->id)->count());
        }

        $received = $this->transaction($submission, 'received-restrict');
        $case->forceFill(['received_payment_transaction_id' => $received->id])->save();
        $this->expectException(QueryException::class);
        DB::table('payment_transactions')->where('id', $received->id)->delete();
    }

    public function test_resolution_and_refund_idempotency_keys_remain_unique(): void
    {
        $first = $this->resolutionCase($this->submission($this->orderWithItem()[0], 'idempotency-submission-one'), 'case-idempotency');
        $secondSubmission = $this->submission($this->orderWithItem()[0], 'idempotency-submission-two');

        try {
            $this->resolutionCase($secondSubmission, 'case-idempotency');
            $this->fail('La base permitió repetir la clave de idempotencia del caso.');
        } catch (QueryException) {
            $this->assertSame(1, PaymentResolutionCase::where('idempotency_key', 'case-idempotency')->count());
        }

        $this->refund($first, 'refund-idempotency');
        $second = $this->resolutionCase($secondSubmission, 'case-idempotency-second');
        $this->expectException(QueryException::class);
        $this->refund($second, 'refund-idempotency');
    }

    public function test_resolution_actors_are_null_on_delete_and_history_is_append_only(): void
    {
        $actor = User::factory()->create();
        $case = $this->resolutionCase($this->submission($this->orderWithItem()[0], 'case-actor-submission'), 'case-actor', $actor);
        $history = PaymentResolutionHistory::create([
            'payment_resolution_case_id' => $case->id,
            'event' => 'opened',
            'to_status' => PaymentResolutionCase::OPEN,
            'actor_id' => $actor->id,
            'occurred_at' => now(),
        ]);
        $refund = $this->refund($case, 'refund-actor', $actor);
        $case->forceFill(['resolved_by' => $actor->id])->save();
        $refund->forceFill(['completed_by' => $actor->id])->save();

        $actor->delete();

        $this->assertNull($case->fresh()->requested_by);
        $this->assertNull($case->fresh()->resolved_by);
        $this->assertNull($history->fresh()->actor_id);
        $this->assertNull($refund->fresh()->requested_by);
        $this->assertNull($refund->fresh()->completed_by);

        $this->expectException(LogicException::class);
        $history->update(['reason' => 'No permitido']);
    }

    public function test_resolution_history_keeps_only_allowlisted_safe_metadata(): void
    {
        $case = $this->resolutionCase($this->submission($this->orderWithItem()[0], 'case-history-submission'), 'case-history');
        $history = PaymentResolutionHistory::create([
            'payment_resolution_case_id' => $case->id,
            'event' => 'opened',
            'to_status' => PaymentResolutionCase::OPEN,
            'safe_metadata' => [
                'resolution_type' => PaymentResolutionCase::REFUND,
                'reservation_count' => 1,
                'operation_number' => 'No permitido',
            ],
            'occurred_at' => now(),
        ]);

        $this->assertSame([
            'resolution_type' => PaymentResolutionCase::REFUND,
            'reservation_count' => 1,
        ], $history->fresh()->safe_metadata);
    }

    public function test_refund_reference_is_encrypted_and_sensitive_values_are_hidden(): void
    {
        $case = $this->resolutionCase($this->submission($this->orderWithItem()[0], 'case-private-submission'), 'case-private');
        $refund = $this->refund($case, 'refund-private', null, 'YAPE-REF-12345');

        $stored = DB::table('payment_refunds')->where('id', $refund->id)->value('external_reference');
        $this->assertNotSame('YAPE-REF-12345', $stored);
        $this->assertSame('YAPE-REF-12345', $refund->fresh()->external_reference);
        $this->assertArrayNotHasKey('external_reference', $refund->toArray());
        $this->assertArrayNotHasKey('idempotency_key', $refund->toArray());
        $this->assertArrayNotHasKey('idempotency_key', $case->toArray());
    }

    public function test_resolution_and_refund_models_enforce_allowed_states_and_full_refund_contract(): void
    {
        $case = $this->resolutionCase($this->submission($this->orderWithItem()[0], 'contract-submission'), 'contract-case');

        try {
            $case->forceFill(['type' => 'invented'])->save();
            $this->fail('El modelo aceptó un tipo de resolución desconocido.');
        } catch (InvalidArgumentException) {
            $this->assertNull($case->fresh()->type);
        }

        try {
            $case->forceFill(['status' => 'invented'])->save();
            $this->fail('El modelo aceptó un estado de resolución desconocido.');
        } catch (InvalidArgumentException) {
            $this->assertSame(PaymentResolutionCase::OPEN, $case->fresh()->status);
        }

        try {
            $this->refund($case, 'contract-refund-wrong-amount', null, null, '10.00');
            $this->fail('El modelo aceptó una devolución parcial en la infraestructura de devolución total.');
        } catch (InvalidArgumentException) {
            $this->assertSame(0, PaymentRefund::count());
        }

        try {
            $this->refund($case, 'contract-refund-wrong-currency', null, null, '12.50', 'USD');
            $this->fail('El modelo aceptó una moneda de devolución distinta del caso.');
        } catch (InvalidArgumentException) {
            $this->assertSame(0, PaymentRefund::count());
        }

        $refund = $this->refund($case, 'contract-refund-correct');
        $this->assertSame((string) $case->amount, (string) $refund->amount);
        $this->assertSame($case->currency, $refund->currency);
    }

    public function test_relations_casts_and_payment_status_infrastructure_are_available(): void
    {
        $case = $this->resolutionCase($this->submission($this->orderWithItem()[0], 'case-relations-submission'), 'case-relations');
        $refund = $this->refund($case, 'refund-relations');

        $this->assertTrue($case->submission->is($case->fresh()->submission));
        $this->assertTrue($case->refund->is($refund));
        $this->assertTrue($refund->resolutionCase->is($case));
        $this->assertSame('12.50', $case->fresh()->amount);
        $this->assertSame('12.50', $refund->fresh()->amount);

        $order = $this->orderWithItem()[0];
        $order->update(['payment_status' => 'refund_pending']);
        $this->assertSame('refund_pending', $order->fresh()->payment_status);
        $this->assertContains(PaymentSubmission::MANUAL_RESOLUTION_REQUIRED, PaymentSubmission::STATUSES);
        $this->assertContains(PaymentSubmission::REFUND_PENDING, PaymentSubmission::STATUSES);
        $this->assertContains(PaymentSubmission::REFUNDED, PaymentSubmission::STATUSES);
        $this->assertSame('received', PaymentTransaction::RECEIVED);
    }

    public function test_resolution_schema_uses_short_explicit_indexes_and_expected_foreign_keys(): void
    {
        $this->assertLessThanOrEqual(64, strlen('ir_item_sequence_unique'));
        $this->assertLessThanOrEqual(64, strlen('prc_submission_unique'));
        $this->assertLessThanOrEqual(64, strlen('pr_refund_tx_unique'));

        if (DB::connection()->getDriverName() === 'sqlite') {
            $indexes = collect(DB::select("PRAGMA index_list('inventory_reservations')"));
            $this->assertSame(1, (int) $indexes->firstWhere('name', 'ir_item_sequence_unique')->unique);
            $foreignKeys = collect(DB::select("PRAGMA foreign_key_list('inventory_reservations')"));
            $this->assertSame('payment_resolution_cases', $foreignKeys->firstWhere('from', 'resolution_case_id')->table);
            $caseIndexes = collect(DB::select("PRAGMA index_list('payment_resolution_cases')"));
            $this->assertSame(1, (int) $caseIndexes->firstWhere('name', 'prc_submission_unique')->unique);
            $refundIndexes = collect(DB::select("PRAGMA index_list('payment_refunds')"));
            $this->assertSame(1, (int) $refundIndexes->firstWhere('name', 'pr_case_unique')->unique);

            return;
        }

        if (DB::connection()->getDriverName() === 'mysql') {
            $index = DB::selectOne("SHOW INDEX FROM inventory_reservations WHERE Key_name = 'ir_item_sequence_unique'");
            $foreignKey = DB::selectOne("SELECT kcu.REFERENCED_TABLE_NAME AS referenced_table, rc.DELETE_RULE AS delete_rule FROM information_schema.KEY_COLUMN_USAGE kcu JOIN information_schema.REFERENTIAL_CONSTRAINTS rc ON rc.CONSTRAINT_SCHEMA = kcu.CONSTRAINT_SCHEMA AND rc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME WHERE kcu.CONSTRAINT_SCHEMA = DATABASE() AND kcu.CONSTRAINT_NAME = 'ir_resolution_case_fk'");
            $this->assertSame(0, (int) $index->Non_unique);
            $this->assertSame('payment_resolution_cases', $foreignKey->referenced_table);
            $this->assertSame('RESTRICT', strtoupper($foreignKey->delete_rule));

            return;
        }

        $this->markTestSkipped('El driver no expone introspección portable para esta certificación.');
    }

    public function test_reservation_versioning_rollback_restores_the_legacy_unique_when_all_sequences_are_one(): void
    {
        [$order, $item] = $this->orderWithItem();
        $this->reservation($order, $item, null, 'rollback-allowed');

        $this->migration('2026_09_26_070610_version_inventory_reservations_for_resolution_cases.php')->down();

        $this->assertFalse(Schema::hasColumn('inventory_reservations', 'reservation_sequence'));
        $this->assertFalse(Schema::hasColumn('inventory_reservations', 'resolution_case_id'));
        try {
            DB::table('inventory_reservations')->insert([
                'order_id' => $order->id,
                'order_item_id' => $item->id,
                'product_id' => $item->product_id,
                'warehouse_id' => $item->warehouse_id,
                'quantity' => 1,
                'status' => InventoryReservation::EXPIRED,
                'expires_at' => now(),
                'idempotency_key' => 'rollback-legacy-duplicate',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->fail('El rollback no restauró el UNIQUE legacy de order_item_id.');
        } catch (QueryException) {
            $this->migration('2026_09_26_070610_version_inventory_reservations_for_resolution_cases.php')->up();
            $this->assertTrue(Schema::hasColumns('inventory_reservations', ['reservation_sequence', 'resolution_case_id']));
        }
    }

    public function test_reservation_versioning_rollback_refuses_to_modify_a_versioned_schema(): void
    {
        [$order, $item] = $this->orderWithItem();
        $first = $this->reservation($order, $item, null, 'rollback-blocked-first');
        $second = $this->reservation($order, $item, 2, 'rollback-blocked-second');
        $before = InventoryReservation::orderBy('id')->get(['id', 'order_item_id', 'reservation_sequence', 'idempotency_key'])->map->toArray()->all();

        try {
            $this->migration('2026_09_26_070610_version_inventory_reservations_for_resolution_cases.php')->down();
            $this->fail('El rollback eliminó columnas de reservas versionadas.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('reservas versionadas', $exception->getMessage());
        }

        $this->assertTrue(Schema::hasColumns('inventory_reservations', ['reservation_sequence', 'resolution_case_id']));
        $this->assertSame($before, InventoryReservation::orderBy('id')->get(['id', 'order_item_id', 'reservation_sequence', 'idempotency_key'])->map->toArray()->all());
        $this->assertSame(1, $first->fresh()->reservation_sequence);
        $this->assertSame(2, $second->fresh()->reservation_sequence);
    }

    public function test_refund_pending_schema_rollback_allows_legacy_rows_and_refuses_live_refund_pending_orders(): void
    {
        $migration = $this->migration('2026_09_26_070620_add_refund_pending_to_order_payment_status.php');
        $legacyOrder = $this->orderWithItem()[0];

        $migration->down();

        $this->assertSame('pending', $legacyOrder->fresh()->payment_status);
        if (DB::connection()->getDriverName() === 'sqlite') {
            $definition = DB::selectOne("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'orders'")?->sql;
            $this->assertStringNotContainsString('refund_pending', (string) $definition);
        } elseif (DB::connection()->getDriverName() === 'mysql') {
            $definition = DB::selectOne("SHOW COLUMNS FROM orders LIKE 'payment_status'")->Type;
            $this->assertStringNotContainsString('refund_pending', (string) $definition);
        }
        try {
            DB::table('orders')->where('id', $legacyOrder->id)->update(['payment_status' => 'refund_pending']);
            $this->fail('El rollback dejó permitido refund_pending en el esquema legacy.');
        } catch (QueryException) {
            $migration->up();
            DB::table('orders')->where('id', $legacyOrder->id)->update(['payment_status' => 'refund_pending']);
            $this->assertSame('refund_pending', DB::table('orders')->where('id', $legacyOrder->id)->value('payment_status'));
        }
    }

    public function test_refund_pending_schema_rollback_refuses_to_remove_a_live_status_without_changing_the_schema(): void
    {
        $order = $this->orderWithItem()[0];
        $order->update(['payment_status' => 'refund_pending']);

        try {
            $this->migration('2026_09_26_070620_add_refund_pending_to_order_payment_status.php')->down();
            $this->fail('El rollback retiró refund_pending con pedidos que todavía lo usan.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('refund_pending', $exception->getMessage());
        }

        $this->assertSame('refund_pending', $order->fresh()->payment_status);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'payment_status' => 'refund_pending']);
    }

    private function orderWithItem(): array
    {
        $user = User::factory()->create();
        $branch = Branch::create(['code' => 'BR-'.uniqid(), 'name' => 'Sede', 'address' => 'Dirección', 'is_active' => true]);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'code' => 'WH-'.uniqid(), 'name' => 'Almacén', 'is_active' => true]);
        $product = Product::create(['name' => 'Producto '.uniqid(), 'slug' => 'producto-'.uniqid(), 'price' => '12.50', 'stock' => 10]);
        $order = Order::create(['user_id' => $user->id, 'status' => 'pending', 'total' => '12.50', 'shipping_info' => ['recipient_name' => 'Cliente'], 'payment_method' => 'transferencia', 'payment_status' => 'pending', 'delivery_type' => 'delivery', 'tracking_status' => 'pending']);
        $item = OrderItem::create(['order_id' => $order->id, 'product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'quantity' => 1, 'price' => '12.50', 'subtotal' => '12.50']);

        return [$order, $item];
    }

    private function reservation(Order $order, OrderItem $item, ?int $sequence, string $key): InventoryReservation
    {
        $attributes = [
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'product_id' => $item->product_id,
            'warehouse_id' => $item->warehouse_id,
            'quantity' => 1,
            'status' => InventoryReservation::EXPIRED,
            'expires_at' => now()->subMinute(),
            'released_at' => now(),
            'idempotency_key' => $key,
        ];

        if ($sequence === null) {
            return InventoryReservation::create($attributes);
        }

        return InventoryReservation::forceCreate($attributes + ['reservation_sequence' => $sequence]);
    }

    private function submission(Order $order, string $key): PaymentSubmission
    {
        return PaymentSubmission::create([
            'order_id' => $order->id,
            'channel' => 'yape',
            'status' => PaymentSubmission::MANUAL_RESOLUTION_REQUIRED,
            'expected_amount' => '12.50',
            'currency' => 'PEN',
            'operation_number' => '0001'.$order->id,
            'normalized_operation_number' => '0001'.$order->id,
            'duplicate_fingerprint' => hash('sha256', 'fingerprint-'.$key),
            'declared_paid_at' => now(),
            'receiving_account_snapshot' => [],
            'submitted_by' => $order->user_id,
            'submitted_at' => now(),
            'idempotency_key' => $key,
        ]);
    }

    private function resolutionCase(PaymentSubmission $submission, string $key, ?User $actor = null): PaymentResolutionCase
    {
        return PaymentResolutionCase::forceCreate([
            'payment_submission_id' => $submission->id,
            'status' => PaymentResolutionCase::OPEN,
            'amount' => '12.50',
            'currency' => 'PEN',
            'requested_by' => $actor?->id,
            'requested_at' => now(),
            'idempotency_key' => $key,
        ]);
    }

    private function refund(PaymentResolutionCase $case, string $key, ?User $actor = null, ?string $reference = null, string $amount = '12.50', string $currency = 'PEN'): PaymentRefund
    {
        return PaymentRefund::forceCreate([
            'payment_resolution_case_id' => $case->id,
            'amount' => $amount,
            'currency' => $currency,
            'status' => PaymentRefund::PENDING,
            'reason' => 'Sin stock completo',
            'external_reference' => $reference,
            'requested_by' => $actor?->id,
            'requested_at' => now(),
            'idempotency_key' => $key,
        ]);
    }

    private function transaction(PaymentSubmission $submission, string $key, string $type = PaymentTransaction::PAYMENT, string $status = PaymentTransaction::RECEIVED): PaymentTransaction
    {
        return PaymentTransaction::forceCreate([
            'order_id' => $submission->order_id,
            'payment_method' => 'transferencia',
            'transaction_type' => $type,
            'status' => $status,
            'amount' => '12.50',
            'currency' => 'PEN',
            'idempotency_key' => $key,
            'payment_submission_id' => $type === PaymentTransaction::PAYMENT ? $submission->id : null,
        ]);
    }

    private function migration(string $filename): object
    {
        return require database_path('migrations/'.$filename);
    }
}
