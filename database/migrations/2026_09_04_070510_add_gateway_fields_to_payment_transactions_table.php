<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->string('gateway', 40)->nullable()->after('order_id');
            $table->string('provider_payment_id')->nullable()->after('external_reference');
            $table->foreignId('payment_preference_id')->nullable()->after('provider_payment_id')->constrained('payment_preferences')->restrictOnDelete();
            $table->string('provider_status', 40)->nullable()->after('payment_preference_id');
            $table->string('provider_status_detail', 255)->nullable()->after('provider_status');
            $table->timestamp('verified_at')->nullable()->after('provider_status_detail');
            $table->unique(['gateway', 'provider_payment_id'], 'payment_transactions_gateway_payment_unique');
        });
    }

    public function down(): void
    {
        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->dropUnique('payment_transactions_gateway_payment_unique');
            $table->dropForeign(['payment_preference_id']);
            $table->dropColumn(['gateway', 'provider_payment_id', 'payment_preference_id', 'provider_status', 'provider_status_detail', 'verified_at']);
        });
    }
};
