<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_specifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->text('value');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['product_id', 'sort_order']);
        });

        // Keep the legacy column as a temporary backup while exposing its text through the new relation.
        if (! Schema::hasTable('products') || ! Schema::hasColumn('products', 'specifications')) {
            return;
        }

        $timestamp = now()->toDateTimeString();

        DB::table('products')
            ->select(['id', 'specifications'])
            ->whereNotNull('specifications')
            ->whereRaw("TRIM(specifications) <> ''")
            ->orderBy('id')
            ->chunkById(100, function ($products) use ($timestamp): void {
                $productIds = $products->pluck('id')->all();
                $existingProductIds = DB::table('product_specifications')
                    ->whereIn('product_id', $productIds)
                    ->pluck('product_id')
                    ->map(fn ($id) => (int) $id)
                    ->all();
                $existing = array_fill_keys($existingProductIds, true);
                $rows = [];

                foreach ($products as $product) {
                    $value = trim((string) $product->specifications);
                    if ($value === '' || isset($existing[(int) $product->id])) {
                        continue;
                    }

                    $rows[] = [
                        'product_id' => $product->id,
                        'name' => 'Información técnica',
                        'value' => $value,
                        'sort_order' => 0,
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ];
                    $existing[(int) $product->id] = true;
                }

                if ($rows !== []) {
                    DB::table('product_specifications')->insert($rows);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_specifications');
    }
};
