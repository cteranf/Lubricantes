<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_refunds', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('payment_resolution_case_id');
            $table->unsignedBigInteger('refund_payment_transaction_id')->nullable();
            $table->decimal('amount', 12, 2);
            $table->char('currency', 3)->default('PEN');
            $table->string('status', 30)->default('pending');
            $table->string('reason', 1000);
            $table->text('external_reference')->nullable();
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->unsignedBigInteger('completed_by')->nullable();
            $table->timestamp('requested_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('idempotency_key', 100)->unique('pr_idempotency_unique');
            $table->timestamps();

            $table->unique('payment_resolution_case_id', 'pr_case_unique');
            $table->unique('refund_payment_transaction_id', 'pr_refund_tx_unique');
            $table->index(['status', 'requested_at'], 'pr_status_requested_idx');
            $table->foreign('payment_resolution_case_id', 'pr_case_fk')
                ->references('id')->on('payment_resolution_cases')->restrictOnDelete();
            $table->foreign('refund_payment_transaction_id', 'pr_refund_tx_fk')
                ->references('id')->on('payment_transactions')->restrictOnDelete();
            $table->foreign('requested_by', 'pr_requested_by_fk')
                ->references('id')->on('users')->nullOnDelete();
            $table->foreign('completed_by', 'pr_completed_by_fk')
                ->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_refunds');
    }
};
