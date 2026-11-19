<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_profiles', function (Blueprint $table): void {
            $table->id();
            $table->string('singleton_key', 20)->default('primary')->unique();
            $table->string('commercial_name', 150)->nullable();
            $table->string('legal_name', 255)->nullable();
            $table->string('tax_id_type', 20)->nullable();
            $table->string('tax_id_number', 32)->nullable();
            $table->char('country_code', 2)->default('PE');
            $table->string('fiscal_address', 255)->nullable();
            $table->string('contact_email', 255)->nullable();
            $table->string('contact_phone', 40)->nullable();
            $table->string('logo_path', 1000)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_profiles');
    }
};
