<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('shipping_address_id')->nullable()->after('shipping_amount')->constrained('user_addresses')->nullOnDelete();
            $table->foreignId('shipping_zone_id')->nullable()->after('shipping_address_id')->constrained('shipping_zones')->nullOnDelete();
            $table->foreignId('shipping_rate_id')->nullable()->after('shipping_zone_id')->constrained('shipping_rates')->nullOnDelete();
            $table->string('shipping_zone_code_snapshot', 50)->nullable()->after('shipping_rate_id');
            $table->string('shipping_zone_name_snapshot')->nullable()->after('shipping_zone_code_snapshot');
            $table->string('shipping_department_snapshot', 100)->nullable()->after('shipping_zone_name_snapshot');
            $table->string('shipping_province_snapshot', 100)->nullable()->after('shipping_department_snapshot');
            $table->string('shipping_district_snapshot', 100)->nullable()->after('shipping_province_snapshot');
            $table->string('shipping_ubigeo_snapshot', 20)->nullable()->after('shipping_district_snapshot');
            $table->unsignedSmallInteger('shipping_estimated_days_min_snapshot')->nullable()->after('shipping_ubigeo_snapshot');
            $table->unsignedSmallInteger('shipping_estimated_days_max_snapshot')->nullable()->after('shipping_estimated_days_min_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['shipping_address_id']);
            $table->dropForeign(['shipping_zone_id']);
            $table->dropForeign(['shipping_rate_id']);
            $table->dropColumn([
                'shipping_address_id', 'shipping_zone_id', 'shipping_rate_id',
                'shipping_zone_code_snapshot', 'shipping_zone_name_snapshot',
                'shipping_department_snapshot', 'shipping_province_snapshot',
                'shipping_district_snapshot', 'shipping_ubigeo_snapshot',
                'shipping_estimated_days_min_snapshot', 'shipping_estimated_days_max_snapshot',
            ]);
        });
    }
};
