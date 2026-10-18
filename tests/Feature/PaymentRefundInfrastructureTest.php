<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\PaymentRefund;
use App\Models\PaymentResolutionCase;
use App\Models\PaymentSubmission;
use App\Models\PaymentTransaction;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use LogicException;
use Tests\TestCase;

class PaymentRefundInfrastructureTest extends TestCase
{
    use RefreshDatabase;

    public function test_general_total_refund_supports_an_original_payment_without_submission_or_resolution_case(): void
    {
        $original = $this->approvedPayment('general');
        $refund = $this->refund($original, 'general');

        $this->assertNull($refund->payment_resolution_case_id);
        $this->assertTrue($refund->originalPaymentTransaction->is($original));
        $this->assertNull($refund->resolutionCase);
        $this->assertNull($refund->refundPaymentTransaction);
        $this->assertTrue($original->fresh()->refundAsOriginalPayment->is($refund));

        $serialized = $refund->load([
            'originalPaymentTransaction',
            'resolutionCase',
            'refundPaymentTransaction',
        ])->toArray();

        $this->assertArrayNotHasKey('external_reference', $serialized);
        $this->assertArrayNotHasKey('idempotency_key', $serialized);
        $this->assertArrayNotHasKey('reason', $serialized);
        $this->assertArrayNotHasKey('original_payment_transaction', $serialized);
        $this->assertArrayNotHasKey('resolution_case', $serialized);
        $this->assertArrayNotHasKey('refund_payment_transaction', $serialized);
    }

    public function test_original_payment_foreign_key_rejects_a_missing_transaction_without_partial_row(): void
    {
        $before = PaymentRefund::count();

        try {
            DB::table('payment_refunds')->insert($this->refundRow([
                'original_payment_transaction_id' => PHP_INT_MAX,
                'idempotency_key' => 'refund-missing-original',
            ]));
            $this->fail('La base permitió una transacción de pago original inexistente.');
        } catch (QueryException $exception) {
            $this->assertForeignKeyViolation($exception);
        }

        $this->assertSame($before, PaymentRefund::count());
        $this->assertSame(1, DB::selectOne('SELECT 1 AS usable')->usable);
    }

    public function test_original_payment_transaction_is_unique_and_cannot_be_changed_after_creation(): void
    {
        $original = $this->approvedPayment('original-unique');
        $refund = $this->refund($original, 'original-unique-first');

        try {
            $this->refund($original, 'original-unique-second');
            $this->fail('La base permitió dos reembolsos totales para el mismo pago original.');
        } catch (QueryException $exception) {
            $this->assertUniqueViolation($exception, 'pr_original_tx_unique');
        }

        $anotherOriginal = $this->approvedPayment('original-immutable');
        try {
            $refund->forceFill(['original_payment_transaction_id' => $anotherOriginal->id])->save();
            $this->fail('El modelo permitió cambiar la identidad del pago original.');
        } catch (LogicException) {
            $this->assertSame($original->id, $refund->fresh()->original_payment_transaction_id);
        }
    }

    public function test_original_payment_foreign_key_restricts_transaction_deletion(): void
    {
        $original = $this->approvedPayment('original-restrict');
        $this->refund($original, 'original-restrict');

        try {
            DB::table('payment_transactions')->where('id', $original->id)->delete();
            $this->fail('La base permitió borrar el pago original de un reembolso.');
        } catch (QueryException $exception) {
            $this->assertForeignKeyViolation($exception, 1451);
        }

        $this->assertDatabaseHas('payment_transactions', ['id' => $original->id]);
    }

    public function test_optional_resolution_case_accepts_a_valid_case_and_preserves_case_uniqueness(): void
    {
        $firstOriginal = $this->approvedPayment('case-first');
        $case = $this->resolutionCase($firstOriginal, 'case-first');
        $refund = $this->refund($firstOriginal, 'case-first', $case);

        $this->assertTrue($refund->resolutionCase->is($case));
        $this->assertTrue($case->refund->is($refund));

        $secondOriginal = $this->approvedPayment('case-second');
        try {
            $this->refund($secondOriginal, 'case-second', $case);
            $this->fail('La base permitió dos reembolsos para el mismo caso.');
        } catch (QueryException $exception) {
            $this->assertUniqueViolation($exception, 'pr_case_unique');
        }
    }

    public function test_refund_accounting_transaction_keeps_its_foreign_key_unique_and_relation(): void
    {
        $firstOriginal = $this->approvedPayment('accounting-first');
        $secondOriginal = $this->approvedPayment('accounting-second');
        $accounting = $this->refundTransaction($firstOriginal->order, 'accounting');
        $first = $this->refund($firstOriginal, 'accounting-first');
        $first->forceFill(['refund_payment_transaction_id' => $accounting->id])->save();

        $this->assertTrue($first->fresh()->refundPaymentTransaction->is($accounting));
        $this->assertTrue($accounting->fresh()->refundAsAccountingTransaction->is($first));

        $second = $this->refund($secondOriginal, 'accounting-second');
        try {
            $second->forceFill(['refund_payment_transaction_id' => $accounting->id])->save();
            $this->fail('La base permitió reutilizar el asiento contable de devolución.');
        } catch (QueryException $exception) {
            $this->assertUniqueViolation($exception, 'pr_refund_tx_unique');
        }

        try {
            DB::table('payment_refunds')->where('id', $second->id)->update([
                'refund_payment_transaction_id' => PHP_INT_MAX,
            ]);
            $this->fail('La base permitió un asiento contable de devolución inexistente.');
        } catch (QueryException $exception) {
            $this->assertForeignKeyViolation($exception);
        }

        $this->assertNull($second->fresh()->refund_payment_transaction_id);
    }

    public function test_same_transaction_cannot_be_used_as_original_payment_and_refund_accounting_entry(): void
    {
        $original = $this->approvedPayment('same-transaction');

        $this->expectException(InvalidArgumentException::class);
        PaymentRefund::forceCreate($this->refundAttributes($original, 'same-transaction') + [
            'refund_payment_transaction_id' => $original->id,
        ]);
    }

    public function test_same_transaction_identity_is_rejected_even_when_one_identifier_is_a_string(): void
    {
        $original = $this->approvedPayment('same-transaction-cast');

        $this->expectException(InvalidArgumentException::class);
        PaymentRefund::forceCreate(array_merge($this->refundAttributes($original, 'same-transaction-cast'), [
            'original_payment_transaction_id' => (string) $original->id,
            'refund_payment_transaction_id' => $original->id,
        ]));
    }

    public function test_refund_model_rejects_non_payment_non_approved_or_non_total_original_transactions(): void
    {
        $pending = $this->approvedPayment('pending');
        DB::table('payment_transactions')->where('id', $pending->id)->update(['status' => PaymentTransaction::PENDING]);

        try {
            $this->refund($pending->fresh(), 'pending');
            $this->fail('El modelo aceptó como origen una transacción no aprobada.');
        } catch (InvalidArgumentException) {
            $this->assertSame(0, PaymentRefund::count());
        }

        $refundTransaction = $this->refundTransaction($pending->order, 'wrong-type');
        try {
            $this->refund($refundTransaction, 'wrong-type');
            $this->fail('El modelo aceptó como origen un asiento de devolución.');
        } catch (InvalidArgumentException) {
            $this->assertSame(0, PaymentRefund::count());
        }

        $original = $this->approvedPayment('wrong-amount');
        try {
            PaymentRefund::forceCreate(array_merge(
                $this->refundAttributes($original, 'wrong-amount'),
                ['amount' => '1.00']
            ));
            $this->fail('El modelo aceptó un reembolso parcial.');
        } catch (InvalidArgumentException) {
            $this->assertSame(0, PaymentRefund::count());
        }
    }

    public function test_schema_exposes_nullable_case_and_stable_original_payment_constraints(): void
    {
        $this->assertTrue(Schema::hasColumn('payment_refunds', 'original_payment_transaction_id'));
        $this->assertLessThanOrEqual(64, strlen('pr_original_tx_unique'));
        $this->assertLessThanOrEqual(64, strlen('pr_original_tx_fk'));

        if (DB::connection()->getDriverName() === 'sqlite') {
            $columns = collect(DB::select("PRAGMA table_info('payment_refunds')"));
            $this->assertSame(0, (int) $columns->firstWhere('name', 'payment_resolution_case_id')->notnull);
            $this->assertSame(0, (int) $columns->firstWhere('name', 'original_payment_transaction_id')->notnull);

            $indexes = collect(DB::select("PRAGMA index_list('payment_refunds')"));
            $this->assertSame(1, (int) $indexes->firstWhere('name', 'pr_original_tx_unique')->unique);
            $this->assertSame(1, (int) $indexes->firstWhere('name', 'pr_case_unique')->unique);
            $this->assertSame(1, (int) $indexes->firstWhere('name', 'pr_refund_tx_unique')->unique);

            $foreignKeys = collect(DB::select("PRAGMA foreign_key_list('payment_refunds')"));
            $this->assertSame('payment_transactions', $foreignKeys->firstWhere('from', 'original_payment_transaction_id')->table);
            $this->assertSame('RESTRICT', strtoupper($foreignKeys->firstWhere('from', 'original_payment_transaction_id')->on_delete));

            return;
        }

        $columns = collect(DB::select("SHOW COLUMNS FROM payment_refunds WHERE Field IN ('payment_resolution_case_id', 'original_payment_transaction_id')"));
        $this->assertSame('YES', $columns->firstWhere('Field', 'payment_resolution_case_id')->Null);
        $this->assertSame('YES', $columns->firstWhere('Field', 'original_payment_transaction_id')->Null);

        foreach (['pr_original_tx_unique', 'pr_case_unique', 'pr_refund_tx_unique'] as $indexName) {
            $index = DB::selectOne("SHOW INDEX FROM payment_refunds WHERE Key_name = '{$indexName}'");
            $this->assertSame(0, (int) $index->Non_unique);
        }

        $foreignKey = DB::selectOne("SELECT kcu.REFERENCED_TABLE_NAME AS referenced_table, rc.DELETE_RULE AS delete_rule FROM information_schema.KEY_COLUMN_USAGE kcu JOIN information_schema.REFERENTIAL_CONSTRAINTS rc ON rc.CONSTRAINT_SCHEMA = kcu.CONSTRAINT_SCHEMA AND rc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME WHERE kcu.CONSTRAINT_SCHEMA = DATABASE() AND kcu.CONSTRAINT_NAME = 'pr_original_tx_fk'");
        $this->assertSame('payment_transactions', $foreignKey->referenced_table);
        $this->assertSame('RESTRICT', strtoupper($foreignKey->delete_rule));
    }

    public function test_migration_down_restores_legacy_case_requirement_and_up_backfills_original_payment(): void
    {
        $original = $this->approvedPayment('rollback');
        $case = $this->resolutionCase($original, 'rollback');
        $refund = $this->refund($original, 'rollback', $case);
        $migration = $this->migration();
        try {
            $migration->down();

            $this->assertFalse(Schema::hasColumn('payment_refunds', 'original_payment_transaction_id'));
            $this->assertSame($case->id, DB::table('payment_refunds')->where('id', $refund->id)->value('payment_resolution_case_id'));
            $this->assertCaseColumnIsRequired();

            $migration->up();

            $this->assertTrue(Schema::hasColumn('payment_refunds', 'original_payment_transaction_id'));
            $this->assertSame($original->id, DB::table('payment_refunds')->where('id', $refund->id)->value('original_payment_transaction_id'));
        } finally {
            if (! Schema::hasColumn('payment_refunds', 'original_payment_transaction_id')) {
                $migration->up();
            }
            $this->cleanupMigrationFixture((int) $refund->id, $case, $original);
        }
    }

    public function test_migration_down_refuses_general_refunds_without_changing_the_schema(): void
    {
        $refund = $this->refund($this->approvedPayment('rollback-blocked'), 'rollback-blocked');

        try {
            $this->migration()->down();
            $this->fail('El rollback eliminó soporte general con reembolsos sin caso existentes.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('esquema legacy', $exception->getMessage());
        }

        $this->assertTrue(Schema::hasColumn('payment_refunds', 'original_payment_transaction_id'));
        $this->assertDatabaseHas('payment_refunds', [
            'id' => $refund->id,
            'payment_resolution_case_id' => null,
        ]);
    }

    /** @dataProvider unsafeBackfillMutations */
    public function test_migration_backfill_leaves_unsafe_or_ambiguous_legacy_rows_unlinked(string $field, mixed $value): void
    {
        $original = $this->approvedPayment('unsafe-backfill-'.$field);
        $case = $this->resolutionCase($original, 'unsafe-backfill-'.$field);
        $refund = $this->refund($original, 'unsafe-backfill-'.$field, $case);
        $migration = $this->migration();
        try {
            $migration->down();

            DB::table('payment_transactions')->where('id', $original->id)->update([$field => $value]);
            $migration->up();

            $this->assertNull(DB::table('payment_refunds')->where('id', $refund->id)->value('original_payment_transaction_id'));
        } finally {
            if (! Schema::hasColumn('payment_refunds', 'original_payment_transaction_id')) {
                $migration->up();
            }
            $this->cleanupMigrationFixture((int) $refund->id, $case, $original);
        }
    }

    public static function unsafeBackfillMutations(): array
    {
        return [
            'tipo refund' => ['transaction_type', PaymentTransaction::REFUND],
            'importe cruzado' => ['amount', '24.99'],
            'moneda cruzada' => ['currency', 'USD'],
            'presentación cruzada' => ['payment_submission_id', null],
        ];
    }

    public function test_migration_down_rejects_an_original_not_deducible_from_the_case_before_schema_changes(): void
    {
        $first = $this->approvedPayment('down-original-first');
        $case = $this->resolutionCase($first, 'down-original-first');
        $second = $this->approvedPayment('down-original-second');
        $refund = $this->refund($first, 'down-original-first', $case);
        DB::table('payment_refunds')->where('id', $refund->id)->update(['original_payment_transaction_id' => $second->id]);

        try {
            $this->migration()->down();
            $this->fail('El rollback aceptó una identidad original no deducible del caso.');
        } catch (\RuntimeException) {
            $this->assertTrue(Schema::hasColumn('payment_refunds', 'original_payment_transaction_id'));
            $this->assertSame((int) $second->id, (int) DB::table('payment_refunds')->where('id', $refund->id)->value('original_payment_transaction_id'));
        }
    }

    /** @dataProvider unreconstructibleOriginalStates */
    public function test_down_rejects_each_unreconstructible_original_before_any_ddl(string $state): void
    {
        $original = $this->approvedPayment('down-predicate-'.$state);
        $case = $this->resolutionCase($original, 'down-predicate-'.$state);
        $refund = $this->refund($original, 'down-predicate-'.$state, $case);
        $otherOrder = null;
        $competitor = null;
        $uniqueDropped = false;
        $driver = DB::connection()->getDriverName();
        try {
            if ($state === 'competitor') {
                if ($driver === 'mysql') {
                    DB::statement('ALTER TABLE payment_transactions ADD INDEX b3_predicate_submission_support (payment_submission_id), DROP INDEX pt_submission_unique');
                } else {
                    DB::statement('DROP INDEX pt_submission_unique');
                }
                $uniqueDropped = true;
                $attributes = $original->getAttributes();
                unset($attributes['id'], $attributes['created_at'], $attributes['updated_at']);
                $attributes['approved_scope_key'] = null;
                $attributes['idempotency_key'] = 'predicate-competitor-'.bin2hex(random_bytes(8));
                $competitor = PaymentTransaction::forceCreate($attributes);
            } elseif ($state === 'order') {
                $otherOrder = $original->order->replicate();
                $otherOrder->save();
                DB::table('payment_transactions')->where('id', $original->id)->update(['order_id' => $otherOrder->id]);
            } elseif ($state === 'received null') {
                DB::table('payment_resolution_cases')->where('id', $case->id)->update(['received_payment_transaction_id' => null]);
            } elseif ($state === 'case amount') {
                DB::table('payment_resolution_cases')->where('id', $case->id)->update(['amount' => '24.99']);
            } else {
                [$field, $value] = match ($state) {
                    'type' => ['transaction_type', PaymentTransaction::REFUND],
                    'status' => ['status', PaymentTransaction::RECEIVED],
                    'submission' => ['payment_submission_id', null],
                    'amount' => ['amount', '24.99'],
                    'currency' => ['currency', 'USD'],
                };
                DB::table('payment_transactions')->where('id', $original->id)->update([$field => $value]);
            }
            $before = $this->originalPaymentConstraintSnapshot();
            try {
                $this->migration()->down();
                $this->fail('An unreconstructible original must block rollback before DDL.');
            } catch (\RuntimeException) {
                $this->assertSame($before, $this->originalPaymentConstraintSnapshot());
                $this->assertTrue(Schema::hasColumn('payment_refunds', 'original_payment_transaction_id'));
                $this->assertTrue((int) DB::table('payment_refunds')->where('id', $refund->id)->value('original_payment_transaction_id') === (int) $original->id, 'Rollback must retain the explicit original identity.');
            }
        } finally {
            if ($competitor) {
                DB::table('payment_transactions')->where('id', $competitor->id)->delete();
            }
            if ($uniqueDropped) {
                if ($driver === 'mysql') {
                    DB::statement('ALTER TABLE payment_transactions ADD UNIQUE INDEX pt_submission_unique (payment_submission_id), DROP INDEX b3_predicate_submission_support');
                } else {
                    DB::statement('CREATE UNIQUE INDEX pt_submission_unique ON payment_transactions (payment_submission_id)');
                }
            }
            if ($driver === 'mysql') {
                if ($otherOrder) {
                    DB::table('payment_transactions')->where('id', $original->id)->update(['order_id' => $original->order_id]);
                    DB::table('orders')->where('id', $otherOrder->id)->delete();
                }
                $this->cleanupMigrationFixture((int) $refund->id, $case, $original);
            }
        }
    }

    public static function unreconstructibleOriginalStates(): array
    {
        return array_map(fn ($state) => [$state], ['type', 'status', 'submission', 'order', 'amount', 'currency', 'case amount', 'competitor', 'received null']);
    }

    public function test_migration_down_rejects_a_case_without_received_transaction_before_schema_changes(): void
    {
        $original = $this->approvedPayment('down-case-without-transaction');
        $case = $this->resolutionCase($original, 'down-case-without-transaction');
        $refund = $this->refund($original, 'down-case-without-transaction', $case);
        DB::table('payment_resolution_cases')->where('id', $case->id)->update([
            'received_payment_transaction_id' => null,
        ]);

        try {
            $this->migration()->down();
            $this->fail('El rollback aceptó un caso que no conserva la transacción de pago recibida.');
        } catch (\RuntimeException) {
            $this->assertTrue(Schema::hasColumn('payment_refunds', 'original_payment_transaction_id'));
            $this->assertTrue(Schema::hasColumn('payment_refunds', 'payment_resolution_case_id'));
            $this->assertSame((int) $original->id, (int) DB::table('payment_refunds')->where('id', $refund->id)->value('original_payment_transaction_id'));
            $this->assertNull(DB::table('payment_resolution_cases')->where('id', $case->id)->value('received_payment_transaction_id'));
        }
    }

    public function test_migration_down_rejects_an_unexpected_unique_layout_before_other_schema_changes(): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE payment_refunds ADD INDEX pr_original_tx_support (original_payment_transaction_id), DROP INDEX pr_original_tx_unique, ADD UNIQUE INDEX pr_original_tx_unique (refund_payment_transaction_id)');
        } else {
            DB::statement('DROP INDEX pr_original_tx_unique');
            DB::statement('CREATE UNIQUE INDEX pr_original_tx_unique ON payment_refunds (refund_payment_transaction_id)');
        }

        $corruptedSchema = $this->originalPaymentConstraintSnapshot();

        try {
            $this->migration()->down();
            $this->fail('El rollback aceptó restricciones físicas incompletas.');
        } catch (\RuntimeException) {
            $this->assertTrue(Schema::hasColumn('payment_refunds', 'original_payment_transaction_id'));
            $this->assertTrue(Schema::hasColumn('payment_refunds', 'payment_resolution_case_id'));
            $this->assertSame($corruptedSchema, $this->originalPaymentConstraintSnapshot());
        } finally {
            if ($driver === 'mysql') {
                DB::statement('ALTER TABLE payment_refunds DROP INDEX pr_original_tx_unique, ADD UNIQUE INDEX pr_original_tx_unique (original_payment_transaction_id), DROP INDEX pr_original_tx_support');
            } else {
                DB::statement('DROP INDEX pr_original_tx_unique');
                DB::statement('CREATE UNIQUE INDEX pr_original_tx_unique ON payment_refunds (original_payment_transaction_id)');
            }
        }
    }

    public function test_mysql_migration_down_rejects_a_homonymous_incorrect_foreign_key_before_ddl(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('La sustitución nominal de una FK se certifica físicamente en MySQL.');
        }

        $foreignKey = static fn (): array => DB::table('information_schema.key_column_usage as kcu')
            ->join('information_schema.referential_constraints as rc', function ($join) {
                $join->on('rc.constraint_schema', '=', 'kcu.constraint_schema')
                    ->on('rc.constraint_name', '=', 'kcu.constraint_name')
                    ->on('rc.table_name', '=', 'kcu.table_name');
            })
            ->where('kcu.table_schema', DB::connection()->getDatabaseName())
            ->where('kcu.table_name', 'payment_refunds')->where('kcu.constraint_name', 'pr_original_tx_fk')
            ->orderBy('kcu.ordinal_position')
            ->get(['kcu.column_name as local_column', 'kcu.referenced_table_name as referenced_table', 'kcu.referenced_column_name as referenced_column', 'rc.delete_rule as delete_rule'])
            ->map(fn ($row) => (array) $row)->all();
        $unique = static fn (): array => DB::table('information_schema.statistics')
            ->where('table_schema', DB::connection()->getDatabaseName())
            ->where('table_name', 'payment_refunds')->where('index_name', 'pr_original_tx_unique')
            ->selectRaw('COLUMN_NAME AS column_name, NON_UNIQUE AS non_unique, SEQ_IN_INDEX AS seq_in_index')
            ->orderBy('seq_in_index')->get()
            ->map(fn ($row) => ['column' => $row->column_name, 'non_unique' => (int) $row->non_unique, 'sequence' => (int) $row->seq_in_index])->all();
        $correctForeignKey = [['local_column' => 'original_payment_transaction_id', 'referenced_table' => 'payment_transactions', 'referenced_column' => 'id', 'delete_rule' => 'RESTRICT']];
        $incorrectForeignKey = [['local_column' => 'refund_payment_transaction_id', 'referenced_table' => 'payment_transactions', 'referenced_column' => 'id', 'delete_rule' => 'RESTRICT']];
        $correctUnique = [['column' => 'original_payment_transaction_id', 'non_unique' => 0, 'sequence' => 1]];
        $this->assertSame($correctForeignKey, $foreignKey());
        $this->assertSame($correctUnique, $unique());
        $originalSchema = $this->originalPaymentConstraintSnapshot();
        $checkingDown = false;
        $ddlObserved = false;
        DB::listen(static function ($event) use (&$checkingDown, &$ddlObserved): void {
            if ($checkingDown && preg_match('/^\s*(ALTER|CREATE|DROP|RENAME|TRUNCATE)\b/i', $event->sql)) {
                $ddlObserved = true;
            }
        });
        try {
            DB::statement('ALTER TABLE payment_refunds DROP FOREIGN KEY pr_original_tx_fk');
            $this->assertSame([], $foreignKey());
            DB::statement('ALTER TABLE payment_refunds ADD CONSTRAINT pr_original_tx_fk FOREIGN KEY (refund_payment_transaction_id) REFERENCES payment_transactions (id) ON DELETE RESTRICT');
            $this->assertSame($incorrectForeignKey, $foreignKey());
            $this->assertSame($correctUnique, $unique());
            $corruptedSchema = $this->originalPaymentConstraintSnapshot();
            $checkingDown = true;
            try {
                $this->migration()->down();
                $this->fail('El rollback aceptó una FK homónima con contrato incorrecto.');
            } catch (\RuntimeException $exception) {
                $this->assertTrue($exception::class === \RuntimeException::class, 'Rollback must reject the foreign-key contract before executing DDL.');
                $this->assertTrue($exception->getMessage() === 'Las claves foráneas generalizadas no coinciden con el contrato esperado.', 'Rollback must specifically reject the incorrect foreign key.');
            } finally {
                $checkingDown = false;
            }
            $this->assertFalse($ddlObserved, 'Rollback must not execute DDL when its foreign-key precondition fails.');
            $this->assertSame($corruptedSchema, $this->originalPaymentConstraintSnapshot());
            $this->assertSame($incorrectForeignKey, $foreignKey());
            $this->assertSame($correctUnique, $unique());
        } finally {
            $checkingDown = false;
            try {
                if ($foreignKey() !== []) {
                    DB::statement('ALTER TABLE payment_refunds DROP FOREIGN KEY pr_original_tx_fk');
                }
                $this->assertSame([], $foreignKey());
                DB::statement('ALTER TABLE payment_refunds ADD CONSTRAINT pr_original_tx_fk FOREIGN KEY (original_payment_transaction_id) REFERENCES payment_transactions (id) ON DELETE RESTRICT');
                $this->assertSame($correctForeignKey, $foreignKey());
                $this->assertSame($correctUnique, $unique());
                $this->assertSame($originalSchema, $this->originalPaymentConstraintSnapshot());
                $this->assertSame(1, (int) DB::selectOne('SELECT 1 AS usable')->usable);
            } catch (\Throwable) {
                $this->fail('MySQL foreign-key fixture restoration failed; stop certification and inspect the isolated schema.');
            }
        }
    }

    private function originalPaymentConstraintSnapshot(): array
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            return [
                'columns' => collect(DB::select("SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payment_refunds' ORDER BY ORDINAL_POSITION"))->map(fn ($row) => (array) $row)->all(),
                'indexes' => collect(DB::select("SELECT INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payment_refunds' ORDER BY INDEX_NAME, SEQ_IN_INDEX"))->map(fn ($row) => (array) $row)->all(),
                'foreign_keys' => collect(DB::select("SELECT kcu.CONSTRAINT_NAME, kcu.COLUMN_NAME, kcu.REFERENCED_TABLE_NAME, kcu.REFERENCED_COLUMN_NAME, rc.DELETE_RULE FROM information_schema.KEY_COLUMN_USAGE kcu JOIN information_schema.REFERENTIAL_CONSTRAINTS rc ON rc.CONSTRAINT_SCHEMA = kcu.CONSTRAINT_SCHEMA AND rc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME AND rc.TABLE_NAME = kcu.TABLE_NAME WHERE kcu.CONSTRAINT_SCHEMA = DATABASE() AND kcu.TABLE_NAME = 'payment_refunds' ORDER BY kcu.CONSTRAINT_NAME, kcu.ORDINAL_POSITION"))->map(fn ($row) => (array) $row)->all(),
            ];
        }

        return [
            'columns' => collect(DB::select('PRAGMA table_info(payment_refunds)'))->map(fn ($row) => (array) $row)->all(),
            'indexes' => collect(DB::select('PRAGMA index_list(payment_refunds)'))->map(fn ($row) => (array) $row)->all(),
            'foreign_keys' => collect(DB::select('PRAGMA foreign_key_list(payment_refunds)'))->map(fn ($row) => (array) $row)->all(),
        ];
    }

    private function approvedPayment(string $suffix): PaymentTransaction
    {
        $order = Order::create([
            'user_id' => User::factory()->create()->id,
            'status' => 'pending',
            'total' => '25.00',
            'shipping_info' => ['recipient_name' => 'Cliente'],
            'payment_method' => 'card',
            'payment_status' => 'approved',
            'paid_at' => now(),
            'delivery_type' => 'delivery',
            'tracking_status' => 'pending',
        ]);

        return PaymentTransaction::forceCreate([
            'order_id' => $order->id,
            'gateway' => 'mercadopago',
            'payment_method' => 'card',
            'transaction_type' => PaymentTransaction::PAYMENT,
            'status' => PaymentTransaction::APPROVED,
            'amount' => '25.00',
            'currency' => 'PEN',
            'idempotency_key' => 'payment-'.$suffix.'-'.bin2hex(random_bytes(4)),
            'approved_scope_key' => PaymentTransaction::approvedScopeKeyForOrder($order->id),
            'confirmed_at' => now(),
        ]);
    }

    private function refundTransaction(Order $order, string $suffix): PaymentTransaction
    {
        return PaymentTransaction::forceCreate([
            'order_id' => $order->id,
            'gateway' => 'manual',
            'payment_method' => $order->payment_method,
            'transaction_type' => PaymentTransaction::REFUND,
            'status' => PaymentTransaction::APPROVED,
            'amount' => '25.00',
            'currency' => 'PEN',
            'idempotency_key' => 'refund-transaction-'.$suffix.'-'.bin2hex(random_bytes(4)),
            'confirmed_at' => now(),
        ]);
    }

    private function resolutionCase(PaymentTransaction $original, string $suffix): PaymentResolutionCase
    {
        $submission = PaymentSubmission::create([
            'order_id' => $original->order_id,
            'channel' => 'bank_transfer',
            'status' => PaymentSubmission::MANUAL_RESOLUTION_REQUIRED,
            'expected_amount' => '25.00',
            'currency' => 'PEN',
            'operation_number' => 'B3-'.$suffix,
            'normalized_operation_number' => 'B3-'.$suffix,
            'duplicate_fingerprint' => hash('sha256', 'b3-'.$suffix),
            'declared_paid_at' => now(),
            'receiving_account_snapshot' => [],
            'submitted_by' => $original->order->user_id,
            'submitted_at' => now(),
            'idempotency_key' => 'submission-'.$suffix.'-'.bin2hex(random_bytes(4)),
        ]);

        DB::table('payment_transactions')->where('id', $original->id)->update([
            'payment_submission_id' => $submission->id,
        ]);
        $original->refresh();

        return PaymentResolutionCase::forceCreate([
            'payment_submission_id' => $submission->id,
            'received_payment_transaction_id' => $original->id,
            'status' => PaymentResolutionCase::OPEN,
            'amount' => '25.00',
            'currency' => 'PEN',
            'requested_at' => now(),
            'idempotency_key' => 'case-'.$suffix.'-'.bin2hex(random_bytes(4)),
        ]);
    }

    private function refund(PaymentTransaction $original, string $suffix, ?PaymentResolutionCase $case = null): PaymentRefund
    {
        return PaymentRefund::forceCreate($this->refundAttributes($original, $suffix, $case));
    }

    private function refundAttributes(PaymentTransaction $original, string $suffix, ?PaymentResolutionCase $case = null): array
    {
        return [
            'payment_resolution_case_id' => $case?->id,
            'original_payment_transaction_id' => $original->id,
            'amount' => '25.00',
            'currency' => 'PEN',
            'status' => PaymentRefund::PENDING,
            'reason' => 'Reembolso total manual',
            'requested_at' => now(),
            'idempotency_key' => 'refund-'.$suffix.'-'.bin2hex(random_bytes(4)),
        ];
    }

    private function refundRow(array $overrides = []): array
    {
        return array_merge([
            'payment_resolution_case_id' => null,
            'original_payment_transaction_id' => null,
            'refund_payment_transaction_id' => null,
            'amount' => '25.00',
            'currency' => 'PEN',
            'status' => PaymentRefund::PENDING,
            'reason' => 'Reembolso total manual',
            'requested_at' => now(),
            'idempotency_key' => 'refund-row-'.bin2hex(random_bytes(4)),
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides);
    }

    private function assertForeignKeyViolation(QueryException $exception, int $mysqlDriverCode = 1452): void
    {
        $errorInfo = $exception->errorInfo;
        if (DB::connection()->getDriverName() === 'sqlite') {
            $this->assertSame('23000', $errorInfo[0]);
            $this->assertSame(19, (int) $errorInfo[1]);
            $this->assertTrue(str_contains(strtolower((string) $errorInfo[2]), 'foreign key'), 'La base debe rechazar específicamente la FK.');

            return;
        }

        $this->assertSame('23000', $errorInfo[0]);
        $this->assertSame($mysqlDriverCode, (int) $errorInfo[1]);
    }

    private function assertUniqueViolation(QueryException $exception, string $constraint): void
    {
        $errorInfo = $exception->errorInfo;
        if (DB::connection()->getDriverName() === 'sqlite') {
            $this->assertSame('23000', $errorInfo[0]);
            $this->assertSame(19, (int) $errorInfo[1]);
            $this->assertTrue(str_contains(strtolower((string) $errorInfo[2]), 'unique'), 'La base debe rechazar específicamente el UNIQUE.');

            return;
        }

        $this->assertSame('23000', $errorInfo[0]);
        $this->assertSame(1062, (int) $errorInfo[1]);
        $this->assertTrue(str_contains(strtolower((string) $errorInfo[2]), strtolower($constraint)), 'La base debe identificar el índice UNIQUE esperado.');
    }

    private function assertCaseColumnIsRequired(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            $column = collect(DB::select("PRAGMA table_info('payment_refunds')"))->firstWhere('name', 'payment_resolution_case_id');
            $this->assertSame(1, (int) $column->notnull);

            return;
        }

        $column = DB::selectOne("SHOW COLUMNS FROM payment_refunds WHERE Field = 'payment_resolution_case_id'");
        $this->assertSame('NO', $column->Null);
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_10_11_070630_generalize_payment_refunds_for_total_refunds.php');
    }

    private function cleanupMigrationFixture(int $refundId, PaymentResolutionCase $case, PaymentTransaction $transaction): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        $order = $transaction->order;
        $submissionId = $case->payment_submission_id;
        DB::table('payment_refunds')->where('id', $refundId)->delete();
        DB::table('payment_resolution_cases')->where('id', $case->id)->delete();
        DB::table('payment_transactions')->where('id', $transaction->id)->delete();
        DB::table('payment_submissions')->where('id', $submissionId)->delete();
        DB::table('orders')->where('id', $order->id)->delete();
        DB::table('users')->where('id', $order->user_id)->delete();
    }
}
