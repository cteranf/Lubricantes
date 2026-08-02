<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('contact_inquiries', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 120);
            $table->string('email', 190)->index();
            $table->string('phone', 30)->nullable();
            $table->string('subject', 180);
            $table->text('message');
            $table->string('status', 32)->default('pending');
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('submission_token')->unique();
            $table->string('duplicate_hash', 64)->nullable()->index();
            $table->string('source', 32)->default('web');
            $table->string('ip_hash', 64)->nullable();
            $table->timestamp('attention_started_at')->nullable();
            $table->timestamp('attended_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('archived_at')->nullable()->index();
            $table->timestamp('last_activity_at');
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['assigned_to', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_inquiries');
    }
};
