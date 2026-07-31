<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('fulfillment_status', 20)->nullable()->after('tracking_status')->index();
            $table->timestamp('preparing_at')->nullable()->after('fulfillment_status');
            $table->timestamp('ready_at')->nullable()->after('preparing_at');
            $table->foreignId('prepared_by')->nullable()->after('ready_at')->constrained('users')->nullOnDelete();
            $table->foreignId('ready_by')->nullable()->after('prepared_by')->constrained('users')->nullOnDelete();
            $table->foreignId('delivered_by')->nullable()->after('ready_by')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['prepared_by']);
            $table->dropForeign(['ready_by']);
            $table->dropForeign(['delivered_by']);
            $table->dropIndex(['fulfillment_status']);
            $table->dropColumn(['fulfillment_status','preparing_at','ready_at','prepared_by','ready_by','delivered_by']);
        });
    }
};
