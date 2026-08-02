<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('order_handling_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('incident_id')->nullable()->constrained('order_handling_incidents')->nullOnDelete();
            $table->string('event_type', 40);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('confirmation_method', 30)->default('manual');
            $table->string('observation', 1000)->nullable();
            $table->json('metadata')->nullable();
            $table->string('idempotency_key')->unique();
            $table->timestamp('created_at');
            $table->index(['order_id', 'created_at']);
            $table->index(['order_item_id', 'event_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_handling_histories');
    }
};
