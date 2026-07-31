<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('parent_id');
            $table->index('is_active', 'categories_is_active_index');
        });

        Schema::table('brands', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('logo');
            $table->index('is_active', 'brands_is_active_index');
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropIndex('categories_is_active_index');
            $table->dropColumn('is_active');
        });
        Schema::table('brands', function (Blueprint $table) {
            $table->dropIndex('brands_is_active_index');
            $table->dropColumn('is_active');
        });
    }
};
