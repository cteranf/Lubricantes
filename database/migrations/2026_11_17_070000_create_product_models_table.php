<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** SQLite table reconstruction must run outside Laravel's migration transaction. */
    public $withinTransaction = false;

    public function up(): void
    {
        Schema::create('product_models', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_id')->constrained()->restrictOnDelete();
            $table->string('name', 255);
            $table->string('normalized_name', 255);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['brand_id', 'normalized_name'], 'product_models_brand_normalized_unique');
            $table->unique(['brand_id', 'id'], 'product_models_brand_id_unique');
        });

        if (! Schema::hasTable('products') || Schema::hasColumn('products', 'product_model_id')) {
            return;
        }

        if (DB::connection()->getDriverName() === 'sqlite') {
            try {
                $this->rebuildSqliteProducts(true);
            } catch (Throwable $exception) {
                Schema::dropIfExists('product_models');
                throw $exception;
            }

            return;
        }

        Schema::table('products', function (Blueprint $table): void {
            $table->unsignedBigInteger('product_model_id')->nullable()->after('brand_id');
            $table->index('product_model_id', 'products_product_model_id_index');
            $table->foreign(['brand_id', 'product_model_id'], 'products_brand_model_foreign')
                ->references(['brand_id', 'id'])->on('product_models')->restrictOnDelete();
        });

        DB::unprepared("CREATE TRIGGER products_model_requires_brand_insert BEFORE INSERT ON products FOR EACH ROW BEGIN IF NEW.product_model_id IS NOT NULL AND NEW.brand_id IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A product model requires a brand.'; END IF; END");
        DB::unprepared("CREATE TRIGGER products_model_requires_brand_update BEFORE UPDATE ON products FOR EACH ROW BEGIN IF NEW.product_model_id IS NOT NULL AND NEW.brand_id IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A product model requires a brand.'; END IF; END");
    }

    public function down(): void
    {
        if (Schema::hasTable('products') && Schema::hasColumn('products', 'product_model_id')) {
            if (DB::connection()->getDriverName() === 'sqlite') {
                $this->rebuildSqliteProducts(false);
            } else {
                DB::unprepared('DROP TRIGGER IF EXISTS products_model_requires_brand_insert');
                DB::unprepared('DROP TRIGGER IF EXISTS products_model_requires_brand_update');
                Schema::table('products', function (Blueprint $table): void {
                    $table->dropForeign('products_brand_model_foreign');
                    $table->dropIndex('products_product_model_id_index');
                    $table->dropColumn('product_model_id');
                });
            }
        }

        Schema::dropIfExists('product_models');
    }

    private function rebuildSqliteProducts(bool $addingModel): void
    {
        $connection = DB::connection();
        $pdo = $connection->getPdo();
        $tableSql = $connection->table('sqlite_master')
            ->where('type', 'table')->where('name', 'products')->value('sql');

        if (! is_string($tableSql) || $tableSql === '') {
            throw new RuntimeException('The products table definition is unavailable.');
        }

        $columns = $connection->getSchemaBuilder()->getColumnListing('products');
        $indexes = $connection->table('sqlite_master')
            ->whereIn('type', ['index', 'trigger'])->where('tbl_name', 'products')
            ->whereNotNull('sql')->pluck('sql')->all();
        $newColumns = $addingModel ? [...$columns, 'product_model_id'] : array_values(array_diff($columns, ['product_model_id']));
        $newDefinition = preg_replace('/\A(create\s+table\s+)(?:"products"|`products`|\[products\]|products)/i', '$1"products__model_rebuild"', $tableSql, 1);
        if (! is_string($newDefinition) || $newDefinition === $tableSql) {
            throw new RuntimeException('The products table definition could not be reconstructed safely.');
        }

        $lastParen = strrpos($newDefinition, ')');
        if ($lastParen === false) {
            throw new RuntimeException('The products table definition is malformed.');
        }

        if ($addingModel) {
            $foreignKeyPosition = stripos($newDefinition, ', foreign key');
            $columnPosition = $foreignKeyPosition === false ? $lastParen : $foreignKeyPosition;
            $newDefinition = substr_replace($newDefinition, ', "product_model_id" INTEGER NULL', $columnPosition, 0);
            $lastParen = strrpos($newDefinition, ')');
            $constraints = ', CHECK ("product_model_id" IS NULL OR "brand_id" IS NOT NULL), FOREIGN KEY ("brand_id", "product_model_id") REFERENCES "product_models" ("brand_id", "id") ON DELETE RESTRICT';
            $newDefinition = substr_replace($newDefinition, $constraints.')', $lastParen);
        } else {
            $newDefinition = preg_replace('/,\s*"product_model_id"\s+INTEGER\s+NULL/i', '', $newDefinition, 1);
            $newDefinition = preg_replace('/,\s*CHECK\s*\("product_model_id"\s+IS\s+NULL\s+OR\s+"brand_id"\s+IS\s+NOT\s+NULL\),\s*FOREIGN\s+KEY\s*\("brand_id",\s*"product_model_id"\)\s+REFERENCES\s+"product_models"\s*\("brand_id",\s*"id"\)\s+ON\s+DELETE\s+RESTRICT/i', '', $newDefinition, 1);
            if (! is_string($newDefinition) || str_contains($newDefinition, 'product_model_id')) {
                throw new RuntimeException('The products table model constraint could not be removed safely.');
            }
        }

        $pdo->exec('PRAGMA foreign_keys = OFF');
        try {
            $connection->beginTransaction();
            $pdo->exec('DROP TABLE IF EXISTS "products__model_rebuild"');
            $pdo->exec($newDefinition);
            $targetColumns = implode(', ', array_map(fn (string $column): string => '"'.$column.'"', $newColumns));
            $sourceColumns = array_map(fn (string $column): string => '"'.$column.'"', $addingModel ? $columns : $newColumns);
            if ($addingModel) {
                $sourceColumns[] = 'NULL';
            }
            $pdo->exec('INSERT INTO "products__model_rebuild" ('.$targetColumns.') SELECT '.implode(', ', $sourceColumns).' FROM "products"');
            $pdo->exec('DROP TABLE "products"');
            $pdo->exec('ALTER TABLE "products__model_rebuild" RENAME TO "products"');

            foreach ($indexes as $sql) {
                if (! $addingModel && str_contains(strtolower($sql), 'product_model_id')) {
                    continue;
                }
                $pdo->exec($sql);
            }

            if ($addingModel) {
                $pdo->exec('CREATE INDEX products_product_model_id_index ON products (product_model_id)');
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

        $violations = $connection->select('PRAGMA foreign_key_check');
        if ($violations !== []) {
            throw new RuntimeException('The products table reconstruction introduced foreign key violations.');
        }
    }
};
