<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->string('gateway', 40);
            $table->string('provider_preference_id')->nullable()->unique();
            $table->string('external_reference', 100)->unique();
            $table->string('idempotency_key', 100)->unique();
            $table->string('state', 20);
            // MySQL 5.7+/MariaDB 10.2+: computed by the database, never by Eloquent.
            $table->unsignedBigInteger('active_order_id')->nullable()
                ->storedAs("CASE WHEN state IN ('creating', 'active') THEN order_id ELSE NULL END")
                ->unique('payment_preferences_one_active_order');
            $table->text('init_point')->nullable();
            $table->text('sandbox_init_point')->nullable();
            $table->string('error_code', 100)->nullable();
            $table->string('error_message', 500)->nullable();
            $table->timestamp('creation_started_at');
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('superseded_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();
            $table->index(['order_id', 'gateway', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_preferences');
    }
};
