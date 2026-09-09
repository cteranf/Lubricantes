<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_submission_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_submission_id')->constrained()->restrictOnDelete();
            $table->string('event', 30);
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason', 1000)->nullable();
            $table->json('safe_metadata')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['payment_submission_id', 'occurred_at'], 'psh_submission_occurred_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_submission_histories');
    }
};
