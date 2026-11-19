<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_tax_configurations', function (Blueprint $table): void {
            $table->id();
            $table->string('singleton_key', 20)->default('primary')->unique();
            $table->unsignedInteger('igv_rate_basis_points')->nullable();
            $table->unsignedInteger('ipm_rate_basis_points')->nullable();
            $table->date('effective_from')->nullable();
            $table->boolean('prices_include_tax')->default(true);
            $table->string('shipping_treatment', 30)->default('ancillary_to_goods');
            $table->boolean('is_configured')->default(false);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->string('tax_affectation', 2)->nullable()->after('price');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('tax_affectation');
        });

        Schema::dropIfExists('business_tax_configurations');
    }
};
