<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_addresses', function (Blueprint $table) {
            $table->foreignId('department_id')->nullable()->after('reference')->constrained()->restrictOnDelete();
            $table->foreignId('province_id')->nullable()->after('department_id')->constrained()->restrictOnDelete();
            $table->foreignId('district_id')->nullable()->after('province_id')->constrained()->restrictOnDelete();
        });

        Schema::table('branches', function (Blueprint $table) {
            $table->foreignId('department_id')->nullable()->after('address')->constrained()->restrictOnDelete();
            $table->foreignId('province_id')->nullable()->after('department_id')->constrained()->restrictOnDelete();
            $table->foreignId('district_id')->nullable()->after('province_id')->constrained()->restrictOnDelete();
        });

        Schema::table('shipping_rates', function (Blueprint $table) {
            $table->foreignId('district_id')->nullable()->after('shipping_zone_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('active_district_id')->nullable()->after('is_active')->unique();
        });
    }

    public function down(): void
    {
        Schema::table('shipping_rates', function (Blueprint $table) {
            $table->dropUnique(['active_district_id']);
            $table->dropForeign(['district_id']);
            $table->dropColumn(['district_id', 'active_district_id']);
        });

        Schema::table('branches', function (Blueprint $table) {
            $table->dropForeign(['department_id']);
            $table->dropForeign(['province_id']);
            $table->dropForeign(['district_id']);
            $table->dropColumn(['department_id', 'province_id', 'district_id']);
        });

        Schema::table('user_addresses', function (Blueprint $table) {
            $table->dropForeign(['department_id']);
            $table->dropForeign(['province_id']);
            $table->dropForeign(['district_id']);
            $table->dropColumn(['department_id', 'province_id', 'district_id']);
        });
    }
};
