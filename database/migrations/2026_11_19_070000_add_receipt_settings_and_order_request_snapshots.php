<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_profiles', function (Blueprint $table): void {
            $table->boolean('boleta_enabled')->default(true);
            $table->boolean('factura_enabled')->default(false);
            $table->string('default_receipt_type', 20)->default('boleta');
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->string('requested_receipt_type', 20)->nullable()->index();
            $table->string('requested_identity_type', 20)->nullable();
            $table->string('requested_identity_number', 32)->nullable();
            $table->boolean('requested_identity_required')->nullable();
            $table->string('requested_recipient_name', 255)->nullable();
            $table->string('requested_billing_address', 255)->nullable();
            $table->string('requested_receipt_email', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex(['requested_receipt_type']);
            $table->dropColumn([
                'requested_receipt_type',
                'requested_identity_type',
                'requested_identity_number',
                'requested_identity_required',
                'requested_recipient_name',
                'requested_billing_address',
                'requested_receipt_email',
            ]);
        });

        Schema::table('business_profiles', function (Blueprint $table): void {
            $table->dropColumn(['boleta_enabled', 'factura_enabled', 'default_receipt_type']);
        });
    }
};
