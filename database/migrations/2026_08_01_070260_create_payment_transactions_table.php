<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('payment_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->string('payment_method', 40);
            $table->string('transaction_type', 20);
            $table->string('status', 20);
            $table->decimal('amount', 12, 2);
            $table->char('currency', 3)->default('PEN');
            $table->string('idempotency_key')->unique();
            $table->string('approved_scope_key')->nullable()->unique();
            $table->string('external_reference')->nullable();
            $table->string('manual_reference')->nullable();
            $table->string('collection_method', 30)->nullable();
            $table->foreignId('collected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('collected_at')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('failure_reason', 1000)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['order_id', 'status']);
            $table->index(['payment_method', 'transaction_type', 'status'], 'payment_transaction_lookup_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_transactions');
    }
};
