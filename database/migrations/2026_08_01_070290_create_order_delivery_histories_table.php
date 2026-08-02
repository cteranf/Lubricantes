<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('order_delivery_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_delivery_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_delivery_attempt_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event_type', 50);
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('observation', 1000)->nullable();
            $table->json('metadata')->nullable();
            $table->string('idempotency_key')->unique();
            $table->timestamp('created_at');
            $table->index(['order_id', 'created_at']);
            $table->index(['order_delivery_id', 'event_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_delivery_histories');
    }
};
