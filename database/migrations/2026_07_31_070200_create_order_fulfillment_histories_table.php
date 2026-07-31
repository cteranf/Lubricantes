<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('order_fulfillment_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('observation', 500)->nullable();
            $table->json('metadata')->nullable();
            $table->string('idempotency_key')->unique();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['order_id','created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_fulfillment_histories');
    }
};
