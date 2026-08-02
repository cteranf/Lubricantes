<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('contact_inquiry_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_inquiry_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('body');
            $table->timestamps();

            $table->index(['contact_inquiry_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_inquiry_notes');
    }
};
