<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('tax_calculation_status', 32)->default('pending_configuration')->after('shipping_amount');
            $table->json('tax_snapshot')->nullable()->after('tax_calculation_status');
            $table->string('tax_calculation_version', 64)->nullable()->after('tax_snapshot');
        });

        Schema::table('order_items', function (Blueprint $table): void {
            $table->json('tax_snapshot')->nullable()->after('subtotal');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropColumn('tax_snapshot');
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn(['tax_calculation_status', 'tax_snapshot', 'tax_calculation_version']);
        });
    }
};
