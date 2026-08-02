<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('order_handling_incidents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 30);
            $table->unsignedInteger('affected_quantity')->nullable();
            $table->string('description', 1000);
            $table->string('status', 20)->default('open');
            $table->foreignId('reported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reported_at');
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->string('resolution_observation', 1000)->nullable();
            $table->string('idempotency_key')->nullable()->unique();
            $table->timestamps();
            $table->index(['order_id', 'status']);
            $table->index(['order_item_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_handling_incidents');
    }
};
