<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_review_settings', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->boolean('show_reviews_publicly')->default(false);
            $table->boolean('accept_new_reviews')->default(false);
            $table->boolean('require_moderation')->default(true);
            $table->timestamps();
        });

        $now = now();
        DB::table('product_review_settings')->insert([
            'id' => 1,
            'show_reviews_publicly' => false,
            'accept_new_reviews' => false,
            'require_moderation' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('product_review_settings');
    }
};
