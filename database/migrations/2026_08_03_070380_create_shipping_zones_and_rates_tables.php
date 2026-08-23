<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipping_zones', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('estimated_days_min')->nullable();
            $table->unsignedSmallInteger('estimated_days_max')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('shipping_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipping_zone_id')->constrained()->restrictOnDelete();
            $table->string('department', 100);
            $table->string('province', 100);
            $table->string('district', 100);
            $table->string('ubigeo', 20)->nullable()->index();
            $table->string('normalized_department', 100);
            $table->string('normalized_province', 100);
            $table->string('normalized_district', 100);
            $table->unsignedDecimal('amount', 10, 2);
            $table->unsignedSmallInteger('estimated_days_min')->nullable();
            $table->unsignedSmallInteger('estimated_days_max')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->char('active_district_key', 64)->nullable()->unique();
            $table->char('active_ubigeo_key', 64)->nullable()->unique();
            $table->timestamps();

            $table->index(['normalized_department', 'normalized_province', 'normalized_district'], 'shipping_rates_territory_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipping_rates');
        Schema::dropIfExists('shipping_zones');
    }
};
