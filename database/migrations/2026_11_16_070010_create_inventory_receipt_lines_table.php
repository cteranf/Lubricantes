<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('inventory_receipt_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_receipt_id')->constrained('inventory_receipts')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['inventory_receipt_id', 'product_id'], 'receipt_line_product_unique');
            $table->index(['inventory_receipt_id', 'sort_order', 'id'], 'receipt_line_order_index');
            $table->index('product_id', 'receipt_line_product_index');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('inventory_receipt_lines') && Schema::hasTable('inventory_movements')) {
            $hasConfirmedMovement = DB::table('inventory_movements')
                ->where('reference_type', 'inventory_receipt_line')
                ->exists();

            if ($hasConfirmedMovement) {
                throw new RuntimeException('No se pueden eliminar líneas referenciadas por movimientos de recepción.');
            }
        }

        Schema::dropIfExists('inventory_receipt_lines');
    }
};
