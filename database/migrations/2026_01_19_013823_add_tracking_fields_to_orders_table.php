<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->enum('delivery_type', ['delivery', 'pickup'])->default('delivery')->after('payment_data');
            $table->string('tracking_status')->default('pending')->after('delivery_type');
            $table->text('tracking_notes')->nullable()->after('tracking_status');
            $table->date('estimated_delivery_date')->nullable()->after('tracking_notes');
            $table->timestamp('delivered_at')->nullable()->after('estimated_delivery_date');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['delivery_type', 'tracking_status', 'tracking_notes', 'estimated_delivery_date', 'delivered_at']);
        });
    }
};
