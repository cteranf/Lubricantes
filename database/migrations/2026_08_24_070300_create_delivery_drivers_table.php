<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('delivery_drivers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('first_name', 120);
            $table->string('last_name', 120);
            $table->string('document_type', 30)->nullable();
            $table->string('document_number', 50)->nullable()->unique();
            $table->string('phone', 40);
            $table->string('email')->nullable();
            $table->string('license_number', 80)->nullable();
            $table->string('license_category', 30)->nullable();
            $table->date('license_expires_at')->nullable();
            $table->string('emergency_contact_name')->nullable();
            $table->string('emergency_contact_phone', 40)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_available')->default(true);
            $table->timestamps();
            $table->index(['is_active', 'is_available']);
        });
    }

    public function down(): void { Schema::dropIfExists('delivery_drivers'); }
};
