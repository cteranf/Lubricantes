<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->timestamp('occurred_at');
            $table->string('actor_type', 12);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('action', 80);
            $table->string('category', 32);
            $table->string('auditable_type', 40)->nullable();
            $table->string('auditable_id', 120)->nullable();
            $table->string('result', 16);
            $table->string('failure_code', 64)->nullable();
            $table->char('request_id', 36);
            $table->char('correlation_id', 36)->nullable();
            $table->string('route_name', 120)->nullable();
            $table->string('http_method', 10)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 256)->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->json('metadata')->nullable();
            $table->string('specialized_log_type', 80)->nullable();
            $table->string('specialized_log_id', 120)->nullable();
            $table->string('retention_category', 20);
            $table->timestamp('created_at')->useCurrent();

            $table->index('occurred_at', 'audit_logs_occurred_idx');
            $table->index(['actor_type', 'actor_id'], 'audit_logs_actor_idx');
            $table->index('action', 'audit_logs_action_idx');
            $table->index(['auditable_type', 'auditable_id'], 'audit_logs_entity_idx');
            $table->index('request_id', 'audit_logs_request_idx');
            $table->index('correlation_id', 'audit_logs_correlation_idx');
            $table->index('result', 'audit_logs_result_idx');
            $table->index('retention_category', 'audit_logs_retention_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
