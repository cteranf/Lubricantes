<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_settings', function (Blueprint $table) {
            $table->id();
            // Card / Gateway Online
            $table->boolean('card_enabled')->default(true);
            $table->string('card_gateway', 40)->default('mock');
            $table->string('card_title', 100)->default('Tarjeta de crédito o débito');
            $table->string('card_description', 255)->default('Pago seguro en línea mediante pasarela');
            $table->string('mercadopago_public_key', 255)->nullable();
            $table->text('mercadopago_access_token')->nullable(); // encrypted
            $table->boolean('mercadopago_sandbox')->default(true);

            // Bank Transfer / Digital Wallets
            $table->boolean('transfer_enabled')->default(true);
            $table->string('transfer_title', 100)->default('Transferencia bancaria / Yape / Plin');
            $table->string('transfer_description', 255)->default('Paga mediante transferencia bancaria o billetera digital');
            $table->text('transfer_instructions')->nullable();
            $table->string('transfer_bank_name', 100)->nullable();
            $table->string('transfer_account_number', 100)->nullable();
            $table->string('transfer_cci', 100)->nullable();
            $table->string('transfer_account_holder', 150)->nullable();
            $table->string('transfer_yape_phone', 30)->nullable();
            $table->string('transfer_plin_phone', 30)->nullable();

            // Cash on Delivery (Delivery only)
            $table->boolean('cash_on_delivery_enabled')->default(true);
            $table->string('cash_on_delivery_title', 100)->default('Pago contra entrega');
            $table->string('cash_on_delivery_description', 255)->default('Paga en efectivo al recibir tu pedido en tu domicilio');
            $table->text('cash_on_delivery_instructions')->nullable();

            // Cash at Pickup (Pickup only)
            $table->boolean('cash_at_pickup_enabled')->default(true);
            $table->string('cash_at_pickup_title', 100)->default('Pago al recoger en sede');
            $table->string('cash_at_pickup_description', 255)->default('Paga en efectivo o POS al retirar tus productos en la sede seleccionada');
            $table->text('cash_at_pickup_instructions')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_settings');
    }
};
