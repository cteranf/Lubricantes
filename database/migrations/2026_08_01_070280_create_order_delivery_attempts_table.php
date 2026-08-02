<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('order_delivery_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_delivery_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('attempt_number');
            $table->string('status', 20)->default('scheduled');
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->string('failure_reason', 40)->nullable();
            $table->string('failure_description', 1000)->nullable();
            $table->string('location_reference', 500)->nullable();
            $table->foreignId('responsible_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reported_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['order_delivery_id', 'attempt_number'], 'delivery_attempt_number_unique');
            $table->index(['order_delivery_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_delivery_attempts');
    }
};
