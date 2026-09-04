<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('gateway', 40);
            $table->string('provider_event_id')->nullable();
            $table->string('provider_payment_id')->nullable();
            $table->string('event_type', 80)->nullable();
            $table->string('action', 80)->nullable();
            $table->char('payload_hash', 64);
            $table->char('request_id_hash', 64)->nullable();
            $table->string('idempotency_key', 100)->unique();
            $table->string('status', 20);
            $table->string('failure_reason', 500)->nullable();
            $table->timestamp('received_at');
            $table->timestamp('processing_started_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->index(['gateway', 'provider_payment_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_webhook_events');
    }
};
