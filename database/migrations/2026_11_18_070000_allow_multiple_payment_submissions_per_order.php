<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // In MySQL the unique index also backs the order_id foreign key, so
        // detach that constraint before replacing the one-row-per-order index.
        $driver = DB::connection()->getDriverName();
        $isSqlite = $driver === 'sqlite';
        if ($driver === 'mysql' && $this->foreignKeyExists('payment_submissions_order_id_foreign')) {
            Schema::table('payment_submissions', function (Blueprint $table): void {
                $table->dropForeign('payment_submissions_order_id_foreign');
            });
        }

        if ($this->indexExists('payment_submissions_order_id_unique')) {
            Schema::table('payment_submissions', function (Blueprint $table): void {
                $table->dropUnique('payment_submissions_order_id_unique');
            });
        }

        if (! $this->indexExists('payment_submissions_order_id_id_idx')) {
            Schema::table('payment_submissions', function (Blueprint $table): void {
                $table->index(['order_id', 'id'], 'payment_submissions_order_id_id_idx');
            });
        }

        if (! Schema::hasColumn('payment_submissions', 'amount')) {
            Schema::table('payment_submissions', function (Blueprint $table): void {
                // Older submissions never stored the amount actually reported.
                // Keep it nullable rather than inventing a historical amount.
                $table->decimal('amount', 12, 2)->nullable()->after('expected_amount');
            });
        }

        if ($driver === 'mysql' && ! $this->foreignKeyExists('payment_submissions_order_id_foreign')) {
            Schema::table('payment_submissions', function (Blueprint $table): void {
                $table->foreign('order_id', 'payment_submissions_order_id_foreign')
                    ->references('id')->on('orders')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        $hasMultiple = DB::table('payment_submissions')
            ->select('order_id')
            ->groupBy('order_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($hasMultiple) {
            throw new RuntimeException('Cannot restore one-payment-per-order while orders have multiple payment submissions.');
        }

        $driver = DB::connection()->getDriverName();
        if ($driver === 'mysql' && $this->foreignKeyExists('payment_submissions_order_id_foreign')) {
            Schema::table('payment_submissions', function (Blueprint $table): void {
                $table->dropForeign('payment_submissions_order_id_foreign');
            });
        }
        if ($this->indexExists('payment_submissions_order_id_id_idx')) {
            Schema::table('payment_submissions', function (Blueprint $table): void {
                $table->dropIndex('payment_submissions_order_id_id_idx');
            });
        }
        if (Schema::hasColumn('payment_submissions', 'amount')) {
            Schema::table('payment_submissions', function (Blueprint $table): void {
                $table->dropColumn('amount');
            });
        }
        if (! $this->indexExists('payment_submissions_order_id_unique')) {
            Schema::table('payment_submissions', function (Blueprint $table): void {
                $table->unique('order_id', 'payment_submissions_order_id_unique');
            });
        }
        if ($driver === 'mysql' && ! $this->foreignKeyExists('payment_submissions_order_id_foreign')) {
            Schema::table('payment_submissions', function (Blueprint $table): void {
                $table->foreign('order_id', 'payment_submissions_order_id_foreign')
                    ->references('id')->on('orders')->restrictOnDelete();
            });
        }
    }

    private function indexExists(string $name): bool
    {
        $connection = DB::connection();
        if ($connection->getDriverName() === 'sqlite') {
            return collect($connection->select("PRAGMA index_list('payment_submissions')"))
                ->contains(fn ($index): bool => $index->name === $name);
        }

        return $connection->table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', $connection->getDatabaseName())
            ->where('TABLE_NAME', 'payment_submissions')
            ->where('INDEX_NAME', $name)
            ->exists();
    }

    private function foreignKeyExists(string $name): bool
    {
        $connection = DB::connection();

        return $connection->table('information_schema.TABLE_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', $connection->getDatabaseName())
            ->where('TABLE_NAME', 'payment_submissions')
            ->where('CONSTRAINT_NAME', $name)
            ->where('CONSTRAINT_TYPE', 'FOREIGN KEY')
            ->exists();
    }
};
