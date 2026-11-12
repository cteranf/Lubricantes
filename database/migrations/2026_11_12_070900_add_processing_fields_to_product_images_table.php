<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $this->rebuildSqliteTable(withProcessingFields: true);

            return;
        }

        Schema::table('product_images', function ($table) {
            $table->text('original_path')->nullable();
            $table->json('variants')->nullable();
            $table->unsignedInteger('source_width')->nullable();
            $table->unsignedInteger('source_height')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->string('processing_status', 16)->default('pending');
            $table->string('generation_id', 36)->nullable();
            $table->text('pending_original_path')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->index(['product_id', 'sort_order'], 'product_images_product_sort_idx');
            $table->index(['product_id', 'is_primary'], 'product_images_product_primary_idx');
            $table->index('processing_status', 'product_images_status_idx');
            $table->index('generation_id', 'product_images_generation_idx');
        });

        DB::statement('ALTER TABLE product_images MODIFY image_path VARCHAR(255) NULL');
        DB::table('product_images')->whereNotNull('image_path')->update(['processing_status' => 'ready']);
        $this->markExistingPrimaries();
    }

    public function down(): void
    {
        if (DB::table('product_images')->whereNull('image_path')->exists()) {
            throw new RuntimeException('Cannot roll back product image schema while pending rows exist.');
        }

        if (DB::getDriverName() === 'sqlite') {
            $this->rebuildSqliteTable(withProcessingFields: false);

            return;
        }

        Schema::table('product_images', function ($table) {
            $table->dropIndex('product_images_product_sort_idx');
            $table->dropIndex('product_images_product_primary_idx');
            $table->dropIndex('product_images_status_idx');
            $table->dropIndex('product_images_generation_idx');
            $table->dropColumn([
                'original_path', 'variants', 'source_width', 'source_height', 'is_primary',
                'processing_status', 'generation_id', 'pending_original_path', 'processed_at',
            ]);
        });
        DB::statement('ALTER TABLE product_images MODIFY image_path VARCHAR(255) NOT NULL');
    }

    private function rebuildSqliteTable(bool $withProcessingFields): void
    {
        $connection = DB::connection();
        $foreignKeys = (int) $connection->selectOne('PRAGMA foreign_keys')->foreign_keys;

        try {
            $connection->statement('PRAGMA foreign_keys = OFF');
            $connection->statement('CREATE TABLE product_images_rebuilt (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                product_id INTEGER NOT NULL,
                image_path VARCHAR(255) '.($withProcessingFields ? 'NULL' : 'NOT NULL').',
                sort_order INTEGER NOT NULL DEFAULT 0,
                created_at DATETIME NULL,
                updated_at DATETIME NULL'.($withProcessingFields ? ',
                original_path TEXT NULL,
                variants TEXT NULL,
                source_width INTEGER NULL,
                source_height INTEGER NULL,
                is_primary TINYINT(1) NOT NULL DEFAULT 0,
                processing_status VARCHAR(16) NOT NULL DEFAULT \'pending\',
                generation_id VARCHAR(36) NULL,
                pending_original_path TEXT NULL,
                processed_at DATETIME NULL' : '').',
                FOREIGN KEY(product_id) REFERENCES products(id) ON DELETE CASCADE
            )');

            $extraColumns = $withProcessingFields ? ', NULL, NULL, NULL, NULL, 0, CASE WHEN image_path IS NULL THEN \'pending\' ELSE \'ready\' END, NULL, NULL, NULL' : '';
            $connection->statement('INSERT INTO product_images_rebuilt (id, product_id, image_path, sort_order, created_at, updated_at'.($withProcessingFields ? ', original_path, variants, source_width, source_height, is_primary, processing_status, generation_id, pending_original_path, processed_at' : '').') SELECT id, product_id, image_path, sort_order, created_at, updated_at'.$extraColumns.' FROM product_images');
            $connection->statement('DROP TABLE product_images');
            $connection->statement('ALTER TABLE product_images_rebuilt RENAME TO product_images');

            if ($withProcessingFields) {
                $connection->statement('CREATE INDEX product_images_product_sort_idx ON product_images(product_id, sort_order)');
                $connection->statement('CREATE INDEX product_images_product_primary_idx ON product_images(product_id, is_primary)');
                $connection->statement('CREATE INDEX product_images_status_idx ON product_images(processing_status)');
                $connection->statement('CREATE INDEX product_images_generation_idx ON product_images(generation_id)');
            }
        } finally {
            $connection->statement('PRAGMA foreign_keys = '.($foreignKeys ? 'ON' : 'OFF'));
        }

        if ($withProcessingFields) {
            $this->markExistingPrimaries();
        }
    }

    private function markExistingPrimaries(): void
    {
        $lastProduct = null;
        foreach (DB::table('product_images')->select(['id', 'product_id'])->whereNotNull('image_path')->orderBy('product_id')->orderBy('sort_order')->orderBy('id')->get() as $image) {
            if ((int) $image->product_id === $lastProduct) {
                continue;
            }
            DB::table('product_images')->where('id', $image->id)->update(['is_primary' => true]);
            $lastProduct = (int) $image->product_id;
        }
    }
};
