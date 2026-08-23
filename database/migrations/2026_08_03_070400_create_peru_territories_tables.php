<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name', 100);
            $table->string('normalized_name', 100)->unique();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('provinces', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained()->restrictOnDelete();
            $table->string('code', 50);
            $table->string('name', 100);
            $table->string('normalized_name', 100);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();

            $table->unique(['department_id', 'code']);
            $table->unique(['department_id', 'normalized_name']);
        });

        Schema::create('districts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('province_id')->constrained()->restrictOnDelete();
            $table->string('code', 50);
            $table->string('ubigeo', 20)->nullable()->unique();
            $table->string('name', 100);
            $table->string('normalized_name', 100);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();

            $table->unique(['province_id', 'code']);
            $table->unique(['province_id', 'normalized_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('districts');
        Schema::dropIfExists('provinces');
        Schema::dropIfExists('departments');
    }
};
