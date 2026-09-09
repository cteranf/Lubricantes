<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained()->restrictOnDelete();
            $table->string('channel', 30);
            $table->string('status', 30)->default('pending_review');
            $table->decimal('expected_amount', 12, 2);
            $table->char('currency', 3)->default('PEN');
            $table->string('operation_number', 100);
            $table->string('normalized_operation_number', 100);
            $table->string('duplicate_fingerprint', 64)->unique();
            $table->timestamp('declared_paid_at');
            $table->char('origin_phone_last4', 4)->nullable();
            $table->string('origin_bank', 120)->nullable();
            $table->json('receiving_account_snapshot');
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('decision_reason', 1000)->nullable();
            $table->timestamp('review_expires_at')->nullable();
            $table->string('idempotency_key', 100)->unique();
            $table->timestamps();
            $table->index(['status', 'submitted_at']);
            $table->index(['channel', 'status']);
            $table->index('review_expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_submissions');
    }
};
