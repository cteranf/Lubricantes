<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('order_handling_processes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained()->restrictOnDelete();
            $table->string('picking_status', 20)->default('pending');
            $table->timestamp('picking_started_at')->nullable();
            $table->timestamp('picking_completed_at')->nullable();
            $table->foreignId('picking_started_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('picking_completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('picking_observation', 500)->nullable();
            $table->string('packing_status', 20)->default('pending');
            $table->timestamp('packing_started_at')->nullable();
            $table->timestamp('packing_completed_at')->nullable();
            $table->foreignId('packing_started_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('packing_completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('packing_observation', 500)->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->timestamps();
            $table->index(['picking_status', 'packing_status'], 'handling_process_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_handling_processes');
    }
};
