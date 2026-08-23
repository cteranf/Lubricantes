<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('label', 100)->nullable();
            $table->string('recipient_name');
            $table->string('phone', 30);
            $table->string('address');
            $table->string('reference', 500)->nullable();
            $table->string('department', 100);
            $table->string('province', 100);
            $table->string('district', 100);
            $table->string('ubigeo', 20)->nullable();
            $table->string('normalized_department', 100);
            $table->string('normalized_province', 100);
            $table->string('normalized_district', 100);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['user_id', 'is_active']);
            $table->index(['normalized_department', 'normalized_province', 'normalized_district'], 'user_addresses_territory_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_addresses');
    }
};
