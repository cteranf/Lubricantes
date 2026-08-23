<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('territory_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('original_filename');
            $table->char('checksum', 64)->index();
            $table->string('status', 30)->index();
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('created_departments')->default(0);
            $table->unsignedInteger('created_provinces')->default(0);
            $table->unsignedInteger('created_districts')->default(0);
            $table->unsignedInteger('unchanged_rows')->default(0);
            $table->unsignedInteger('conflict_rows')->default(0);
            $table->unsignedInteger('error_rows')->default(0);
            $table->json('summary')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('territory_imports');
    }
};
