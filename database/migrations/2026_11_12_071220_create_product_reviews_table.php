<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('verified_order_item_id')->nullable()->constrained('order_items')->nullOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->string('title', 120)->nullable();
            $table->text('comment');
            $table->string('status', 20)->default('pending');
            $table->foreignId('moderated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('moderated_at')->nullable();
            $table->string('rejection_reason', 500)->nullable();
            // Immutable proof timestamp preserves the public verified badge if an order is later removed.
            $table->timestamp('verified_purchase_at');
            $table->timestamps();
            $table->unique(['user_id', 'product_id'], 'product_reviews_user_product_unique');
            $table->index(['product_id', 'status', 'created_at'], 'product_reviews_public_list_idx');
            $table->index(['status', 'created_at'], 'product_reviews_admin_list_idx');
            $table->index(['product_id', 'rating', 'status'], 'product_reviews_rating_summary_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_reviews');
    }
};
