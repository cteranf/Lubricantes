<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('order_deliveries', function (Blueprint $table) {
            $table->foreignId('driver_id')->nullable()->after('delivery_user_id')->constrained('delivery_drivers')->nullOnDelete();
            $table->foreignId('vehicle_id')->nullable()->after('driver_id')->constrained('delivery_vehicles')->nullOnDelete();
            $table->string('driver_code_snapshot', 40)->nullable()->after('delivery_user_phone');
            $table->string('driver_name_snapshot', 255)->nullable()->after('driver_code_snapshot');
            $table->string('driver_document_snapshot', 80)->nullable()->after('driver_name_snapshot');
            $table->string('driver_phone_snapshot', 40)->nullable()->after('driver_document_snapshot');
            $table->string('vehicle_code_snapshot', 40)->nullable()->after('vehicle_plate');
            $table->string('vehicle_plate_snapshot', 40)->nullable()->after('vehicle_code_snapshot');
            $table->string('vehicle_description_snapshot', 255)->nullable()->after('vehicle_plate_snapshot');
            $table->index(['driver_id', 'status']);
            $table->index(['vehicle_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('order_deliveries', function (Blueprint $table) {
            $table->dropForeign(['driver_id']);
            $table->dropForeign(['vehicle_id']);
            $table->dropColumn(['driver_id', 'vehicle_id', 'driver_code_snapshot', 'driver_name_snapshot', 'driver_document_snapshot', 'driver_phone_snapshot', 'vehicle_code_snapshot', 'vehicle_plate_snapshot', 'vehicle_description_snapshot']);
        });
    }
};
