<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\PaymentSubmission;
use App\Models\PaymentTransaction;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TreasuryPaymentApprovalInfrastructureTest extends TestCase
{
    use RefreshDatabase;

    public function test_submission_link_is_unique_and_relations_are_explicit(): void
    {
        $order = $this->order();
        $submission = $this->submission($order);
        $transaction = $this->transaction($order, $submission, 'treasury-approval-1');

        $this->assertTrue($transaction->paymentSubmission->is($submission));
        $this->assertTrue($submission->paymentTransaction->is($transaction));
        $this->expectException(QueryException::class);
        $this->transaction($order, $submission, 'treasury-approval-2');
    }

    public function test_submission_foreign_key_is_declared_and_rejects_nonexistent_ids_when_the_driver_supports_alter_table_foreign_keys(): void
    {
        if ($this->usesSqlite()) {
            $this->assertSubmissionForeignKeyDeclaration();

            return;
        }

        $this->expectException(QueryException::class);

        PaymentTransaction::forceCreate([
            ...$this->transactionAttributes($this->order(), 'foreign-key-missing'),
            'payment_submission_id' => 999999,
        ]);
    }

    public function test_submission_restrict_delete_is_declared_and_blocks_deletion_when_the_driver_supports_alter_table_foreign_keys(): void
    {
        if ($this->usesSqlite()) {
            $this->assertSubmissionForeignKeyDeclaration();

            return;
        }

        $order = $this->order();
        $submission = $this->submission($order);
        $this->transaction($order, $submission, 'restricted-submission-delete');

        $this->expectException(QueryException::class);
        $submission->delete();
    }

    public function test_deleting_confirmer_nulls_the_existing_foreign_key_without_deleting_transaction(): void
    {
        $order = $this->order();
        $confirmer = User::factory()->create();
        $transaction = PaymentTransaction::forceCreate([
            ...$this->transactionAttributes($order, 'confirmer-null-on-delete'),
            'confirmed_by' => $confirmer->id,
            'confirmed_at' => now(),
        ]);

        $confirmer->delete();

        $transaction->refresh();
        $this->assertDatabaseHas('payment_transactions', ['id' => $transaction->id]);
        $this->assertNull($transaction->confirmed_by);
    }

    public function test_submission_link_is_not_mass_assignable_and_hostile_fill_cannot_change_it(): void
    {
        $order = $this->order();
        $submission = $this->submission($order);
        $transaction = $this->transaction($order, $submission, 'hostile-fill-link');

        $this->assertNotContains('payment_submission_id', $transaction->getFillable());
        $transaction->fill(['payment_submission_id' => 999999]);

        $this->assertSame($submission->id, $transaction->payment_submission_id);
        $this->assertDatabaseHas('payment_transactions', [
            'id' => $transaction->id,
            'payment_submission_id' => $submission->id,
        ]);
    }

    public function test_sensitive_transaction_keys_are_hidden_from_array_and_json_serialization(): void
    {
        $transaction = $this->transaction($this->order(), null, 'hidden-idempotency-key');
        $transaction->forceFill(['approved_scope_key' => 'hidden-approved-scope'])->saveQuietly();

        $this->assertArrayNotHasKey('idempotency_key', $transaction->toArray());
        $this->assertArrayNotHasKey('approved_scope_key', $transaction->toArray());
        $this->assertStringNotContainsString('hidden-idempotency-key', $transaction->toJson());
        $this->assertStringNotContainsString('hidden-approved-scope', $transaction->toJson());
    }

    public function test_submission_index_and_foreign_key_have_the_expected_names_and_definition(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            $indexes = DB::select("PRAGMA index_list('payment_transactions')");
            $submissionIndex = collect($indexes)->firstWhere('name', 'pt_submission_unique');

            $this->assertNotNull($submissionIndex);
            $this->assertSame(1, (int) $submissionIndex->unique);
            $this->assertSubmissionForeignKeyDeclaration();

            return;
        }

        if ($driver === 'mysql') {
            $index = DB::selectOne("SHOW INDEX FROM payment_transactions WHERE Key_name = 'pt_submission_unique'");
            $foreignKey = DB::selectOne("SELECT kcu.REFERENCED_TABLE_NAME AS referenced_table, kcu.REFERENCED_COLUMN_NAME AS referenced_column, rc.DELETE_RULE AS delete_rule FROM information_schema.REFERENTIAL_CONSTRAINTS rc JOIN information_schema.KEY_COLUMN_USAGE kcu ON rc.CONSTRAINT_SCHEMA = kcu.CONSTRAINT_SCHEMA AND rc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME WHERE rc.CONSTRAINT_SCHEMA = DATABASE() AND rc.CONSTRAINT_NAME = 'pt_submission_fk'");

            $this->assertNotNull($index);
            $this->assertSame(0, (int) $index->Non_unique);
            $this->assertNotNull($foreignKey);
            $this->assertSame('payment_submissions', $foreignKey->referenced_table);
            $this->assertSame('id', $foreignKey->referenced_column);
            $this->assertSame('RESTRICT', strtoupper($foreignKey->delete_rule));

            return;
        }

        $this->markTestSkipped("El driver {$driver} no expone metadatos de índices con una consulta portable para esta certificación.");
    }

    public function test_null_legacy_submission_links_remain_allowed(): void
    {
        $order = $this->order();
        $this->transaction($order, null, 'legacy-1');
        $this->transaction($order, null, 'legacy-2');
        $this->assertSame(2, PaymentTransaction::count());
    }

    private function submission(Order $order): PaymentSubmission
    {
        return PaymentSubmission::create(['order_id' => $order->id, 'channel' => 'yape', 'status' => 'pending_review', 'expected_amount' => 1, 'currency' => 'PEN', 'operation_number' => '0001', 'normalized_operation_number' => '0001', 'duplicate_fingerprint' => hash('sha256', 'submission-'.$order->id), 'declared_paid_at' => now(), 'receiving_account_snapshot' => [], 'submitted_by' => $order->user_id, 'submitted_at' => now(), 'idempotency_key' => 'submission-'.$order->id]);
    }

    private function transaction(Order $order, ?PaymentSubmission $submission, ?string $key): PaymentTransaction
    {
        return PaymentTransaction::forceCreate([
            ...$this->transactionAttributes($order, $key),
            'payment_submission_id' => $submission?->id,
        ]);
    }

    private function transactionAttributes(Order $order, string $key): array
    {
        return [
            'order_id' => $order->id,
            'payment_method' => 'transferencia',
            'transaction_type' => 'payment',
            'status' => 'pending',
            'amount' => 1,
            'currency' => 'PEN',
            'idempotency_key' => $key,
        ];
    }

    private function usesSqlite(): bool
    {
        return DB::connection()->getDriverName() === 'sqlite';
    }

    private function assertSubmissionForeignKeyDeclaration(): void
    {
        $migration = file_get_contents(database_path('migrations/2026_09_25_070570_add_submission_approval_link_to_payment_transactions_table.php'));

        $this->assertStringContainsString("->foreign('payment_submission_id', 'pt_submission_fk')", $migration);
        $this->assertStringContainsString("->references('id')->on('payment_submissions')->restrictOnDelete()", $migration);
    }

    private function order(): Order
    {
        $user = \App\Models\User::factory()->create();

        return Order::create(['user_id' => $user->id, 'status' => 'pending', 'total' => '1.00', 'shipping_info' => ['recipient_name' => 'Cliente'], 'payment_method' => 'transferencia', 'payment_status' => 'pending', 'delivery_type' => 'delivery', 'tracking_status' => 'pending']);
    }
}
