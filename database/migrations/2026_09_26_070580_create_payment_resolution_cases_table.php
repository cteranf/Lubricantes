<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_resolution_cases', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('payment_submission_id');
            $table->unsignedBigInteger('received_payment_transaction_id')->nullable();
            // A case begins open; Treasury chooses reassignment or refund later.
            $table->string('type', 30)->nullable();
            $table->string('status', 30)->default('open');
            $table->decimal('amount', 12, 2);
            $table->char('currency', 3)->default('PEN');
            $table->string('reason', 1000)->nullable();
            $table->timestamp('funds_received_at')->nullable();
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->unsignedBigInteger('resolved_by')->nullable();
            $table->timestamp('requested_at');
            $table->timestamp('resolved_at')->nullable();
            $table->string('idempotency_key', 100)->unique('prc_idempotency_unique');
            $table->timestamps();

            $table->unique('payment_submission_id', 'prc_submission_unique');
            $table->unique('received_payment_transaction_id', 'prc_received_tx_unique');
            $table->index(['status', 'requested_at'], 'prc_status_requested_idx');
            $table->foreign('payment_submission_id', 'prc_submission_fk')
                ->references('id')->on('payment_submissions')->restrictOnDelete();
            $table->foreign('received_payment_transaction_id', 'prc_received_tx_fk')
                ->references('id')->on('payment_transactions')->restrictOnDelete();
            $table->foreign('requested_by', 'prc_requested_by_fk')
                ->references('id')->on('users')->nullOnDelete();
            $table->foreign('resolved_by', 'prc_resolved_by_fk')
                ->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_resolution_cases');
    }
};
