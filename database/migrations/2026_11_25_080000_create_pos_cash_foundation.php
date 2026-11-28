<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_registers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->string('code', 64)->unique();
            $table->string('name', 120);
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['warehouse_id', 'is_active']);
        });

        Schema::create('pos_cashier_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('register_id')->constrained('pos_registers')->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('assigned_by')->constrained('users')->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamp('assigned_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->unique(['register_id', 'user_id'], 'pos_cashier_register_user_unique');
            $table->index(['user_id', 'is_active']);
        });

        Schema::create('pos_cash_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('register_id')->constrained('pos_registers')->restrictOnDelete();
            $table->foreignId('cashier_user_id')->constrained('users')->restrictOnDelete();
            $table->string('status', 16)->default('open');
            $table->decimal('opening_cash', 10, 2);
            $table->timestamp('opened_at');
            $table->string('opening_idempotency_key', 100)->unique();
            $table->char('opening_request_hash', 64);
            $table->foreignId('closed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->decimal('expected_cash', 10, 2)->nullable();
            $table->decimal('counted_cash', 10, 2)->nullable();
            $table->decimal('difference', 10, 2)->nullable();
            $table->text('closing_reason')->nullable();
            $table->string('closing_idempotency_key', 100)->nullable()->unique();
            $table->char('closing_request_hash', 64)->nullable();
            $table->timestamps();
            $table->index(['register_id', 'status']);
            $table->index(['cashier_user_id', 'status']);
        });

        // Guard rows make the one-open-session limits enforceable with unique keys
        // in both MySQL/InnoDB and SQLite, including concurrent inserts.
        Schema::create('pos_register_open_sessions', function (Blueprint $table): void {
            $table->unsignedBigInteger('register_id')->primary();
            $table->unsignedBigInteger('session_id')->unique();
            $table->foreign('register_id')->references('id')->on('pos_registers')->restrictOnDelete();
            $table->foreign('session_id')->references('id')->on('pos_cash_sessions')->restrictOnDelete();
            $table->timestamp('created_at');
        });

        Schema::create('pos_cashier_open_sessions', function (Blueprint $table): void {
            $table->unsignedBigInteger('cashier_user_id')->primary();
            $table->unsignedBigInteger('register_id');
            $table->unsignedBigInteger('session_id')->unique();
            $table->foreign('cashier_user_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('register_id')->references('id')->on('pos_registers')->restrictOnDelete();
            $table->foreign('session_id')->references('id')->on('pos_cash_sessions')->restrictOnDelete();
            $table->timestamp('created_at');
        });

        Schema::create('pos_cash_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('session_id')->constrained('pos_cash_sessions')->restrictOnDelete();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('order_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->foreignId('payment_transaction_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->string('kind', 16);
            $table->decimal('cash_received', 10, 2)->nullable();
            $table->decimal('change_given', 10, 2)->nullable();
            $table->decimal('net_amount', 10, 2);
            $table->string('idempotency_key', 120)->unique();
            $table->timestamp('created_at');
            $table->index(['session_id', 'created_at']);
        });

        Schema::create('pos_audit_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('register_id')->nullable()->constrained('pos_registers')->restrictOnDelete();
            $table->foreignId('session_id')->nullable()->constrained('pos_cash_sessions')->restrictOnDelete();
            $table->foreignId('assignment_id')->nullable()->constrained('pos_cashier_assignments')->restrictOnDelete();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->string('event', 40);
            $table->json('before_values')->nullable();
            $table->json('after_values')->nullable();
            $table->timestamp('created_at');
            $table->index(['register_id', 'created_at']);
            $table->index(['session_id', 'created_at']);
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->string('sales_channel', 16)->default('web')->index();
            $table->foreignId('pos_sold_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('pos_register_id')->nullable()->constrained('pos_registers')->restrictOnDelete();
            $table->foreignId('pos_cash_session_id')->nullable()->constrained('pos_cash_sessions')->restrictOnDelete();
            $table->json('pos_buyer_snapshot')->nullable();
            $table->index(['sales_channel', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropForeign(['pos_cash_session_id']);
            $table->dropForeign(['pos_register_id']);
            $table->dropForeign(['pos_sold_by']);
            $table->dropIndex(['sales_channel']);
            $table->dropIndex(['sales_channel', 'created_at']);
            $table->dropColumn(['sales_channel', 'pos_sold_by', 'pos_register_id', 'pos_cash_session_id', 'pos_buyer_snapshot']);
        });

        Schema::dropIfExists('pos_audit_events');
        Schema::dropIfExists('pos_cash_movements');
        Schema::dropIfExists('pos_cashier_open_sessions');
        Schema::dropIfExists('pos_register_open_sessions');
        Schema::dropIfExists('pos_cash_sessions');
        Schema::dropIfExists('pos_cashier_assignments');
        Schema::dropIfExists('pos_registers');
    }
};
