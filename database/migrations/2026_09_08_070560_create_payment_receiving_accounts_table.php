<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_receiving_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('channel', 30);
            $table->string('display_name', 120);
            $table->string('holder_name', 150);
            $table->char('currency', 3)->default('PEN');
            $table->text('phone')->nullable();
            $table->string('bank_name', 120)->nullable();
            $table->text('account_number')->nullable();
            $table->text('cci')->nullable();
            $table->string('qr_path')->nullable();
            $table->string('qr_disk', 60)->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->string('active_default_channel', 30)->nullable()->unique();
            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['channel', 'is_active']);
            $table->index(['channel', 'is_default']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_receiving_accounts');
    }
};
