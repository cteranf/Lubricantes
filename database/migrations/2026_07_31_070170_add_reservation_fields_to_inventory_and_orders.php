<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('warehouse_inventories', function (Blueprint $table) {
            $table->unsignedInteger('reserved_quantity')->default(0)->after('quantity');
            $table->index(['warehouse_id', 'reserved_quantity'], 'warehouse_reserved_index');
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('reserved_until')->nullable()->after('payment_data')->index();
            $table->timestamp('paid_at')->nullable()->after('reserved_until');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['reserved_until']);
            $table->dropColumn(['reserved_until', 'paid_at']);
        });
        Schema::table('warehouse_inventories', function (Blueprint $table) {
            $table->dropIndex('warehouse_reserved_index');
            $table->dropColumn('reserved_quantity');
        });
    }
};
