<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('subtotal', 10, 2)->nullable()->after('total');
            $table->decimal('discount_total', 10, 2)->default(0)->after('subtotal');
            $table->decimal('shipping_amount', 10, 2)->default(0)->after('discount_total');
            $table->string('checkout_token', 100)->nullable()->unique()->after('shipping_amount');
            $table->foreignId('pickup_branch_id')->nullable()->after('delivery_type')->constrained('branches')->nullOnDelete();
            $table->string('pickup_branch_code_snapshot', 50)->nullable()->after('pickup_branch_id');
            $table->string('pickup_branch_name_snapshot')->nullable()->after('pickup_branch_code_snapshot');
            $table->string('pickup_address_snapshot')->nullable()->after('pickup_branch_name_snapshot');
            $table->string('pickup_district_snapshot', 100)->nullable()->after('pickup_address_snapshot');
            $table->string('pickup_business_hours_snapshot', 500)->nullable()->after('pickup_district_snapshot');
            $table->text('pickup_instructions_snapshot')->nullable()->after('pickup_business_hours_snapshot');
            $table->timestamp('ready_for_pickup_at')->nullable()->after('ready_at');
            $table->timestamp('pickup_deadline_at')->nullable()->after('ready_for_pickup_at');
            $table->timestamp('picked_up_at')->nullable()->after('pickup_deadline_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['pickup_branch_id']);
            $table->dropUnique(['checkout_token']);
            $table->dropColumn([
                'subtotal', 'discount_total', 'shipping_amount', 'checkout_token', 'pickup_branch_id',
                'pickup_branch_code_snapshot', 'pickup_branch_name_snapshot', 'pickup_address_snapshot',
                'pickup_district_snapshot', 'pickup_business_hours_snapshot', 'pickup_instructions_snapshot',
                'ready_for_pickup_at', 'pickup_deadline_at', 'picked_up_at',
            ]);
        });
    }
};
