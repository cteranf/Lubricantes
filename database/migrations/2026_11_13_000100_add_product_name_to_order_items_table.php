<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Backfill uses the product's current name as a best-effort label. It cannot
     * establish the exact name shown at the time of historical purchases.
     * Missing products intentionally remain null.
     */
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $table->string('product_name')->nullable();
        });

        if (! Schema::hasTable('products') || ! Schema::hasColumn('order_items', 'product_id')) {
            return;
        }

        DB::table('order_items')
            ->whereNull('product_name')
            ->whereNotNull('product_id')
            ->orderBy('id')
            ->chunkById(500, function ($items): void {
                $productIds = $items->pluck('product_id')->unique()->values();
                $names = DB::table('products')->whereIn('id', $productIds)->pluck('name', 'id');

                foreach ($items as $item) {
                    $name = $names->get($item->product_id);
                    if (! is_string($name) || trim($name) === '') {
                        continue;
                    }

                    DB::table('order_items')->where('id', $item->id)->whereNull('product_name')->update([
                        'product_name' => trim($name),
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropColumn('product_name');
        });
    }
};
