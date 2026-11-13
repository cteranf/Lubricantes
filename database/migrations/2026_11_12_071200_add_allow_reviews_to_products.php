<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('products', 'allow_reviews')) {
            Schema::table('products', function (Blueprint $table): void {
                $table->boolean('allow_reviews')->default(true);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('products', 'allow_reviews')) {
            Schema::table('products', function (Blueprint $table): void {
                $table->dropColumn('allow_reviews');
            });
        }
    }
};
