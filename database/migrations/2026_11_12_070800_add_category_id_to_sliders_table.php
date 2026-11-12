<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            Schema::create('sliders_with_category', function (Blueprint $table) {
                $this->defineSliderColumns($table, true);
            });

            DB::statement('INSERT INTO sliders_with_category (id, title, description, image_path, category_id, is_active, sort_order, created_at, updated_at) SELECT id, title, description, image_path, NULL, is_active, sort_order, created_at, updated_at FROM sliders');
            Schema::drop('sliders');
            Schema::rename('sliders_with_category', 'sliders');

            return;
        }

        Schema::table('sliders', function (Blueprint $table) {
            $table->foreignId('category_id')->nullable()->after('image_path')
                ->constrained('categories')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            Schema::create('sliders_without_category', function (Blueprint $table) {
                $this->defineSliderColumns($table, false);
            });

            DB::statement('INSERT INTO sliders_without_category (id, title, description, image_path, is_active, sort_order, created_at, updated_at) SELECT id, title, description, image_path, is_active, sort_order, created_at, updated_at FROM sliders');
            Schema::drop('sliders');
            Schema::rename('sliders_without_category', 'sliders');

            return;
        }

        Schema::table('sliders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('category_id');
        });
    }

    private function defineSliderColumns(Blueprint $table, bool $withCategory): void
    {
        $table->id();
        $table->string('title');
        $table->text('description')->nullable();
        $table->string('image_path');
        if ($withCategory) {
            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();
        }
        $table->boolean('is_active')->default(true);
        $table->integer('sort_order')->default(0);
        $table->timestamps();
    }
};
