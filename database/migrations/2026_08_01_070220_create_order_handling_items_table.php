<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('order_handling_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_handling_process_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete();
            $table->string('product_name');
            $table->string('product_sku', 64)->nullable();
            $table->string('product_presentation')->nullable();
            $table->string('warehouse_name')->nullable();
            $table->unsignedInteger('ordered_quantity');
            $table->unsignedInteger('picked_quantity')->default(0);
            $table->unsignedInteger('packed_quantity')->default(0);
            $table->string('confirmation_method', 30)->default('manual');
            $table->string('scanned_code')->nullable();
            $table->json('confirmation_metadata')->nullable();
            $table->foreignId('last_operated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_operated_at')->nullable();
            $table->string('observation', 500)->nullable();
            $table->timestamps();
            $table->unique(['order_handling_process_id', 'order_item_id'], 'handling_process_item_unique');
            $table->index(['warehouse_id', 'product_id'], 'handling_item_stock_reference_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_handling_items');
    }
};
