<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            $this->rebuildSqlite(true);

            return;
        }

        Schema::table('payment_refunds', function (Blueprint $table) {
            $table->unsignedBigInteger('original_payment_transaction_id')
                ->nullable()
                ->after('payment_resolution_case_id');
        });

        $this->backfillOriginalPaymentTransaction();

        Schema::table('payment_refunds', function (Blueprint $table) {
            $table->unique('original_payment_transaction_id', 'pr_original_tx_unique');
            $table->foreign('original_payment_transaction_id', 'pr_original_tx_fk')
                ->references('id')->on('payment_transactions')->restrictOnDelete();
            $table->dropForeign('pr_case_fk');
        });

        DB::statement('ALTER TABLE payment_refunds MODIFY payment_resolution_case_id BIGINT UNSIGNED NULL');

        Schema::table('payment_refunds', function (Blueprint $table) {
            $table->foreign('payment_resolution_case_id', 'pr_case_fk')
                ->references('id')->on('payment_resolution_cases')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        $this->assertDownPreconditions();

        if (DB::connection()->getDriverName() === 'sqlite') {
            $this->rebuildSqlite(false);

            return;
        }

        DB::statement(<<<'SQL'
            ALTER TABLE payment_refunds
                DROP FOREIGN KEY pr_original_tx_fk,
                DROP INDEX pr_original_tx_unique,
                DROP COLUMN original_payment_transaction_id,
                MODIFY payment_resolution_case_id BIGINT UNSIGNED NOT NULL
        SQL);
    }

    private function backfillOriginalPaymentTransaction(): void
    {
        DB::statement(<<<'SQL'
            UPDATE payment_refunds
            SET original_payment_transaction_id = (
                SELECT pt.id
                FROM payment_resolution_cases AS prc
                INNER JOIN payment_submissions AS ps ON ps.id = prc.payment_submission_id
                INNER JOIN payment_transactions AS pt ON pt.id = prc.received_payment_transaction_id
                WHERE prc.id = payment_refunds.payment_resolution_case_id
                  AND pt.transaction_type = 'payment'
                  AND pt.status = 'approved'
                  AND pt.payment_submission_id = prc.payment_submission_id
                  AND pt.order_id = ps.order_id
                  AND pt.amount = payment_refunds.amount
                  AND pt.currency = payment_refunds.currency
                  AND prc.amount = payment_refunds.amount
                  AND prc.currency = payment_refunds.currency
                  AND NOT EXISTS (
                      SELECT 1
                      FROM payment_transactions AS competing
                      WHERE competing.id <> pt.id
                        AND competing.payment_submission_id = prc.payment_submission_id
                        AND competing.order_id = ps.order_id
                        AND competing.transaction_type = 'payment'
                        AND competing.status = 'approved'
                        AND competing.amount = payment_refunds.amount
                        AND competing.currency = payment_refunds.currency
                  )
            )
            WHERE original_payment_transaction_id IS NULL
              AND payment_resolution_case_id IS NOT NULL
        SQL);
    }

    private function rebuildSqlite(bool $generalized): void
    {
        DB::statement('PRAGMA foreign_keys = OFF');
        DB::statement('PRAGMA legacy_alter_table = ON');

        try {
            Schema::create('payment_refunds_b3a0_new', function (Blueprint $table) use ($generalized) {
                $table->id();
                $case = $table->unsignedBigInteger('payment_resolution_case_id');
                if ($generalized) {
                    $case->nullable();
                    $table->unsignedBigInteger('original_payment_transaction_id')->nullable();
                }
                $table->unsignedBigInteger('refund_payment_transaction_id')->nullable();
                $table->decimal('amount', 12, 2);
                $table->char('currency', 3)->default('PEN');
                $table->string('status', 30)->default('pending');
                $table->string('reason', 1000);
                $table->text('external_reference')->nullable();
                $table->unsignedBigInteger('requested_by')->nullable();
                $table->unsignedBigInteger('completed_by')->nullable();
                $table->timestamp('requested_at');
                $table->timestamp('completed_at')->nullable();
                $table->timestamp('failed_at')->nullable();
                $table->string('idempotency_key', 100);
                $table->timestamps();

                $table->foreign('payment_resolution_case_id', 'pr_case_fk')
                    ->references('id')->on('payment_resolution_cases')->restrictOnDelete();
                if ($generalized) {
                    $table->foreign('original_payment_transaction_id', 'pr_original_tx_fk')
                        ->references('id')->on('payment_transactions')->restrictOnDelete();
                }
                $table->foreign('refund_payment_transaction_id', 'pr_refund_tx_fk')
                    ->references('id')->on('payment_transactions')->restrictOnDelete();
                $table->foreign('requested_by', 'pr_requested_by_fk')
                    ->references('id')->on('users')->nullOnDelete();
                $table->foreign('completed_by', 'pr_completed_by_fk')
                    ->references('id')->on('users')->nullOnDelete();
            });

            $legacyColumns = [
                'id',
                'payment_resolution_case_id',
                'refund_payment_transaction_id',
                'amount',
                'currency',
                'status',
                'reason',
                'external_reference',
                'requested_by',
                'completed_by',
                'requested_at',
                'completed_at',
                'failed_at',
                'idempotency_key',
                'created_at',
                'updated_at',
            ];
            $targetColumns = $legacyColumns;
            $selectColumns = $legacyColumns;

            if ($generalized) {
                array_splice($targetColumns, 2, 0, ['original_payment_transaction_id']);
                array_splice($selectColumns, 2, 0, [DB::raw(<<<'SQL'
                    (SELECT pt.id
                     FROM payment_resolution_cases AS prc
                     INNER JOIN payment_submissions AS ps ON ps.id = prc.payment_submission_id
                     INNER JOIN payment_transactions AS pt ON pt.id = prc.received_payment_transaction_id
                     WHERE prc.id = payment_refunds.payment_resolution_case_id
                       AND pt.transaction_type = 'payment'
                       AND pt.status = 'approved'
                       AND pt.payment_submission_id = prc.payment_submission_id
                       AND pt.order_id = ps.order_id
                       AND pt.amount = payment_refunds.amount
                       AND pt.currency = payment_refunds.currency
                       AND prc.amount = payment_refunds.amount
                       AND prc.currency = payment_refunds.currency
                       AND NOT EXISTS (
                           SELECT 1 FROM payment_transactions AS competing
                           WHERE competing.id <> pt.id
                             AND competing.payment_submission_id = prc.payment_submission_id
                             AND competing.order_id = ps.order_id
                             AND competing.transaction_type = 'payment'
                             AND competing.status = 'approved'
                             AND competing.amount = payment_refunds.amount
                             AND competing.currency = payment_refunds.currency
                       ))
                SQL)]);
            }

            DB::table('payment_refunds_b3a0_new')->insertUsing(
                $targetColumns,
                DB::table('payment_refunds')->select($selectColumns)
            );

            Schema::drop('payment_refunds');
            Schema::rename('payment_refunds_b3a0_new', 'payment_refunds');

            Schema::table('payment_refunds', function (Blueprint $table) use ($generalized) {
                $table->unique('payment_resolution_case_id', 'pr_case_unique');
                if ($generalized) {
                    $table->unique('original_payment_transaction_id', 'pr_original_tx_unique');
                }
                $table->unique('refund_payment_transaction_id', 'pr_refund_tx_unique');
                $table->unique('idempotency_key', 'pr_idempotency_unique');
                $table->index(['status', 'requested_at'], 'pr_status_requested_idx');
            });
        } finally {
            DB::statement('PRAGMA legacy_alter_table = OFF');
            DB::statement('PRAGMA foreign_keys = ON');
        }

        if (DB::select('PRAGMA foreign_key_check') !== []) {
            throw new RuntimeException('La reconstrucción SQLite de payment_refunds dejó claves foráneas inválidas.');
        }
    }

    private function assertDownPreconditions(): void
    {
        if (! Schema::hasColumns('payment_refunds', [
            'payment_resolution_case_id',
            'original_payment_transaction_id',
        ])) {
            throw new RuntimeException('El esquema generalizado de devoluciones no está completo.');
        }

        $invalidRefunds = DB::table('payment_refunds as refunds')
            ->leftJoin('payment_resolution_cases as cases', 'cases.id', '=', 'refunds.payment_resolution_case_id')
            ->where(function ($query) {
                $query->whereNull('refunds.payment_resolution_case_id')
                    ->orWhereNull('cases.id')
                    ->orWhereNull('cases.received_payment_transaction_id')
                    ->orWhereNull('refunds.original_payment_transaction_id');

                if (DB::connection()->getDriverName() === 'mysql') {
                    $query->orWhereRaw('NOT (refunds.original_payment_transaction_id <=> cases.received_payment_transaction_id)');
                } else {
                    $query->orWhereRaw('NOT (refunds.original_payment_transaction_id IS cases.received_payment_transaction_id)');
                }
            })
            ->exists();

        if ($invalidRefunds) {
            throw new RuntimeException('Los datos existentes no permiten restaurar de forma segura el esquema legacy.');
        }

        if (DB::connection()->getDriverName() === 'sqlite') {
            $this->assertSqliteDownSchema();

            return;
        }

        $this->assertMysqlDownSchema();
    }

    private function assertSqliteDownSchema(): void
    {
        $columns = collect(DB::select("PRAGMA table_info('payment_refunds')"))->keyBy('name');
        foreach (['payment_resolution_case_id', 'original_payment_transaction_id', 'refund_payment_transaction_id'] as $column) {
            $definition = $columns->get($column);
            if (! $definition
                || strtoupper((string) $definition->type) !== 'INTEGER'
                || (int) $definition->notnull !== 0) {
                throw new RuntimeException('Las columnas generalizadas no coinciden con el contrato esperado.');
            }
        }

        $expectedIndexes = [
            'pr_case_unique' => ['payment_resolution_case_id'],
            'pr_original_tx_unique' => ['original_payment_transaction_id'],
            'pr_refund_tx_unique' => ['refund_payment_transaction_id'],
            'pr_idempotency_unique' => ['idempotency_key'],
        ];
        $indexes = collect(DB::select("PRAGMA index_list('payment_refunds')"))->keyBy('name');
        foreach ($expectedIndexes as $name => $expectedColumns) {
            $index = $indexes->get($name);
            $actualColumns = collect(DB::select("PRAGMA index_info('{$name}')"))
                ->sortBy('seqno')->pluck('name')->values()->all();
            if (! $index || (int) $index->unique !== 1 || $actualColumns !== $expectedColumns) {
                throw new RuntimeException('Las restricciones generalizadas no coinciden con el contrato esperado.');
            }
        }

        $expectedForeignKeys = [
            'payment_resolution_case_id' => ['payment_resolution_cases', 'id', ['RESTRICT', 'NO ACTION']],
            'original_payment_transaction_id' => ['payment_transactions', 'id', ['RESTRICT', 'NO ACTION']],
            'refund_payment_transaction_id' => ['payment_transactions', 'id', ['RESTRICT', 'NO ACTION']],
            'requested_by' => ['users', 'id', ['SET NULL']],
            'completed_by' => ['users', 'id', ['SET NULL']],
        ];
        $foreignKeys = collect(DB::select("PRAGMA foreign_key_list('payment_refunds')"))->groupBy('from');
        foreach ($expectedForeignKeys as $localColumn => [$table, $referencedColumn, $deleteRules]) {
            $matches = $foreignKeys->get($localColumn, collect());
            if ($matches->count() !== 1) {
                throw new RuntimeException('Las claves foráneas generalizadas no coinciden con el contrato esperado.');
            }
            $foreignKey = $matches->first();
            if ($foreignKey->table !== $table
                || $foreignKey->to !== $referencedColumn
                || ! in_array(strtoupper((string) $foreignKey->on_delete), $deleteRules, true)) {
                throw new RuntimeException('Las claves foráneas generalizadas no coinciden con el contrato esperado.');
            }
        }
    }

    private function assertMysqlDownSchema(): void
    {
        $database = DB::connection()->getDatabaseName();
        $columns = DB::table('information_schema.columns')
            ->selectRaw('COLUMN_NAME AS column_name, DATA_TYPE AS data_type, COLUMN_TYPE AS column_type, IS_NULLABLE AS is_nullable')
            ->where('table_schema', $database)
            ->where('table_name', 'payment_refunds')
            ->whereIn('column_name', ['payment_resolution_case_id', 'original_payment_transaction_id', 'refund_payment_transaction_id'])
            ->get()->keyBy('column_name');
        foreach (['payment_resolution_case_id', 'original_payment_transaction_id', 'refund_payment_transaction_id'] as $column) {
            $definition = $columns->get($column);
            if (! $definition
                || strtolower((string) $definition->data_type) !== 'bigint'
                || ! str_contains(strtolower((string) $definition->column_type), 'unsigned')
                || strtoupper((string) $definition->is_nullable) !== 'YES') {
                throw new RuntimeException('Las columnas generalizadas no coinciden con el contrato esperado.');
            }
        }

        $expectedIndexes = [
            'pr_case_unique' => ['payment_resolution_case_id'],
            'pr_original_tx_unique' => ['original_payment_transaction_id'],
            'pr_refund_tx_unique' => ['refund_payment_transaction_id'],
            'pr_idempotency_unique' => ['idempotency_key'],
        ];
        foreach ($expectedIndexes as $name => $expectedColumns) {
            $rows = DB::table('information_schema.statistics')
                ->selectRaw('COLUMN_NAME AS column_name, NON_UNIQUE AS non_unique, SEQ_IN_INDEX AS sequence')
                ->where('table_schema', $database)
                ->where('table_name', 'payment_refunds')
                ->where('index_name', $name)
                ->orderBy('sequence')->get();
            if ($rows->count() !== count($expectedColumns)
                || $rows->contains(fn ($row) => (int) $row->non_unique !== 0)
                || $rows->pluck('column_name')->values()->all() !== $expectedColumns) {
                throw new RuntimeException('Las restricciones generalizadas no coinciden con el contrato esperado.');
            }
        }

        $expectedForeignKeys = [
            'pr_case_fk' => ['payment_resolution_case_id', 'payment_resolution_cases', 'id', ['RESTRICT', 'NO ACTION']],
            'pr_original_tx_fk' => ['original_payment_transaction_id', 'payment_transactions', 'id', ['RESTRICT', 'NO ACTION']],
            'pr_refund_tx_fk' => ['refund_payment_transaction_id', 'payment_transactions', 'id', ['RESTRICT', 'NO ACTION']],
            'pr_requested_by_fk' => ['requested_by', 'users', 'id', ['SET NULL']],
            'pr_completed_by_fk' => ['completed_by', 'users', 'id', ['SET NULL']],
        ];
        foreach ($expectedForeignKeys as $name => [$localColumn, $referencedTable, $referencedColumn, $deleteRules]) {
            $rows = DB::table('information_schema.key_column_usage as kcu')
                ->join('information_schema.table_constraints as tc', function ($join) {
                    $join->on('tc.constraint_schema', '=', 'kcu.constraint_schema')
                        ->on('tc.table_name', '=', 'kcu.table_name')
                        ->on('tc.constraint_name', '=', 'kcu.constraint_name');
                })
                ->join('information_schema.referential_constraints as rc', function ($join) {
                    $join->on('rc.constraint_schema', '=', 'kcu.constraint_schema')
                        ->on('rc.constraint_name', '=', 'kcu.constraint_name');
                })
                ->selectRaw('kcu.COLUMN_NAME AS local_column, kcu.REFERENCED_TABLE_NAME AS referenced_table, kcu.REFERENCED_COLUMN_NAME AS referenced_column, tc.CONSTRAINT_TYPE AS constraint_type, rc.DELETE_RULE AS delete_rule, kcu.ORDINAL_POSITION AS sequence')
                ->where('kcu.table_schema', $database)
                ->where('kcu.table_name', 'payment_refunds')
                ->where('kcu.constraint_name', $name)
                ->orderBy('sequence')->get();
            if ($rows->count() !== 1) {
                throw new RuntimeException('Las claves foráneas generalizadas no coinciden con el contrato esperado.');
            }
            $foreignKey = $rows->first();
            if ($foreignKey->constraint_type !== 'FOREIGN KEY'
                || $foreignKey->local_column !== $localColumn
                || $foreignKey->referenced_table !== $referencedTable
                || $foreignKey->referenced_column !== $referencedColumn
                || ! in_array(strtoupper((string) $foreignKey->delete_rule), $deleteRules, true)) {
                throw new RuntimeException('Las claves foráneas generalizadas no coinciden con el contrato esperado.');
            }
        }
    }
};
