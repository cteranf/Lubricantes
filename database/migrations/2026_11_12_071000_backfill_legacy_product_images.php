<?php

use App\Models\Product;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $connection = DB::connection();

        $connection->transaction(function () use ($connection): void {
            $connection->table('products')
                ->select('id')
                ->whereNotNull('image_path')
                ->where('image_path', '<>', '')
                ->orderBy('id')
                ->chunkById(100, function ($products) use ($connection): void {
                    foreach ($products as $product) {
                        $legacyPath = $connection->table('products')
                            ->where('id', $product->id)
                            ->lockForUpdate()
                            ->value('image_path');

                        if (Product::safeLegacyImagePath($legacyPath) === null
                            || $connection->table('product_images')->where('product_id', $product->id)->exists()) {
                            continue;
                        }

                        $connection->table('product_images')->insert([
                            'product_id' => $product->id,
                            'image_path' => $legacyPath,
                            'sort_order' => 0,
                            'is_primary' => true,
                            'processing_status' => 'ready',
                        ]);
                    }
                });
        });
    }

    public function down(): void
    {
        // This is a data-preserving backfill. Keep gallery rows on rollback because
        // they may have been edited or become the only published reference since.
    }
};
