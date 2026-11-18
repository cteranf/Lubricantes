<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            $this->rebuildSqliteOrderItems(true);

            return;
        }

        Schema::table('order_items', function (Blueprint $table): void {
            $table->decimal('regular_price', 10, 2)->nullable();
            $table->unsignedBigInteger('promotion_id')->nullable();
            $table->index('promotion_id', 'order_items_promotion_id_index');
            $table->foreign('promotion_id', 'order_items_promotion_id_foreign')
                ->references('id')->on('product_promotions')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            $this->rebuildSqliteOrderItems(false);

            return;
        }

        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropForeign('order_items_promotion_id_foreign');
            $table->dropIndex('order_items_promotion_id_index');
            $table->dropColumn(['regular_price', 'promotion_id']);
        });
    }

    private function rebuildSqliteOrderItems(bool $addingSnapshots): void
    {
        $connection = DB::connection();
        $pdo = $connection->getPdo();
        $tableSql = $connection->table('sqlite_master')
            ->where('type', 'table')->where('name', 'order_items')->value('sql');
        if (! is_string($tableSql) || $tableSql === '') {
            throw new RuntimeException('The order_items table definition is unavailable.');
        }

        $columns = $connection->getSchemaBuilder()->getColumnListing('order_items');
        $indexes = $connection->table('sqlite_master')
            ->whereIn('type', ['index', 'trigger'])->where('tbl_name', 'order_items')
            ->whereNotNull('sql')->pluck('sql')->all();
        $newColumns = $addingSnapshots
            ? [...$columns, 'regular_price', 'promotion_id']
            : array_values(array_diff($columns, ['regular_price', 'promotion_id']));
        $newDefinition = preg_replace('/\A(create\s+table\s+)(?:"order_items"|`order_items`|\[order_items\]|order_items)/i', '$1"order_items__promotion_rebuild"', $tableSql, 1);
        if (! is_string($newDefinition) || $newDefinition === $tableSql) {
            throw new RuntimeException('The order_items table definition could not be reconstructed safely.');
        }

        $lastParen = strrpos($newDefinition, ')');
        if ($lastParen === false) {
            throw new RuntimeException('The order_items table definition is malformed.');
        }

        if ($addingSnapshots) {
            $foreignKeyPosition = stripos($newDefinition, ', foreign key');
            $columnPosition = $foreignKeyPosition === false ? $lastParen : $foreignKeyPosition;
            $newDefinition = substr_replace($newDefinition, ', "regular_price" DECIMAL(10, 2) NULL, "promotion_id" INTEGER NULL', $columnPosition, 0);
            $lastParen = strrpos($newDefinition, ')');
            $constraint = ', FOREIGN KEY ("promotion_id") REFERENCES "product_promotions" ("id") ON DELETE RESTRICT';
            $newDefinition = substr_replace($newDefinition, $constraint.')', $lastParen);
        } else {
            $newDefinition = preg_replace('/,\s*"regular_price"\s+DECIMAL\(10,\s*2\)\s+NULL,\s*"promotion_id"\s+INTEGER\s+NULL/i', '', $newDefinition, 1);
            $newDefinition = preg_replace('/,\s*FOREIGN KEY\s*\("promotion_id"\)\s+REFERENCES\s+"product_promotions"\s*\("id"\)\s+ON DELETE RESTRICT/i', '', $newDefinition, 1);
            if (! is_string($newDefinition) || str_contains(strtolower($newDefinition), 'promotion_id') || str_contains(strtolower($newDefinition), 'regular_price')) {
                throw new RuntimeException('The order_items promotion constraints could not be removed safely.');
            }
        }

        $pdo->exec('PRAGMA foreign_keys = OFF');
        try {
            $connection->beginTransaction();
            $pdo->exec('DROP TABLE IF EXISTS "order_items__promotion_rebuild"');
            $pdo->exec($newDefinition);
            $targetColumns = implode(', ', array_map(fn (string $column): string => '"'.$column.'"', $newColumns));
            $sourceColumns = array_map(fn (string $column): string => '"'.$column.'"', $addingSnapshots ? $columns : $newColumns);
            if ($addingSnapshots) {
                $sourceColumns[] = 'NULL';
                $sourceColumns[] = 'NULL';
            }
            $pdo->exec('INSERT INTO "order_items__promotion_rebuild" ('.$targetColumns.') SELECT '.implode(', ', $sourceColumns).' FROM "order_items"');
            $pdo->exec('DROP TABLE "order_items"');
            $pdo->exec('ALTER TABLE "order_items__promotion_rebuild" RENAME TO "order_items"');

            foreach ($indexes as $sql) {
                if (! $addingSnapshots && str_contains(strtolower($sql), 'promotion_id')) {
                    continue;
                }
                $pdo->exec($sql);
            }
            if ($addingSnapshots) {
                $pdo->exec('CREATE INDEX order_items_promotion_id_index ON order_items (promotion_id)');
            }

            $connection->commit();
        } catch (Throwable $exception) {
            if ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }

            throw $exception;
        } finally {
            $pdo->exec('PRAGMA foreign_keys = ON');
        }

        if ($connection->select('PRAGMA foreign_key_check') !== []) {
            throw new RuntimeException('The order_items table reconstruction introduced foreign key violations.');
        }
    }
};
