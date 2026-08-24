<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('delivery_vehicles', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('plate_number', 40)->nullable()->unique();
            $table->string('vehicle_type', 20);
            $table->string('brand')->nullable();
            $table->string('model')->nullable();
            $table->unsignedSmallInteger('year')->nullable();
            $table->string('color', 50)->nullable();
            $table->decimal('load_capacity_kg', 8, 2)->nullable();
            $table->string('ownership_type', 20)->default('company');
            $table->date('soat_expires_at')->nullable();
            $table->date('technical_inspection_expires_at')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_available')->default(true);
            $table->timestamps();
            $table->index(['is_active', 'is_available']);
        });
    }

    public function down(): void { Schema::dropIfExists('delivery_vehicles'); }
};
