<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_resolution_histories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('payment_resolution_case_id');
            $table->string('event', 40);
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('reason', 1000)->nullable();
            $table->json('safe_metadata')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['payment_resolution_case_id', 'occurred_at', 'id'], 'prh_case_occurred_idx');
            $table->foreign('payment_resolution_case_id', 'prh_case_fk')
                ->references('id')->on('payment_resolution_cases')->restrictOnDelete();
            $table->foreign('actor_id', 'prh_actor_fk')
                ->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_resolution_histories');
    }
};
