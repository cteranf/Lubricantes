<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('electronic_invoice_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('singleton_key', 20)->default('primary')->unique();
            $table->string('connection_type', 30)->default('direct_sunat');
            $table->string('provider_name', 80)->nullable();
            $table->string('environment', 20)->default('testing');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });

        Schema::create('electronic_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignId('related_document_id')->nullable()->constrained('electronic_documents')->restrictOnDelete();
            $table->uuid('draft_reference')->unique();
            $table->uuid('preparation_key');
            $table->string('document_type', 20);
            $table->string('status', 20)->default('draft');
            $table->string('environment', 20);
            $table->string('connection_type', 30);
            $table->json('seller_snapshot');
            $table->json('recipient_snapshot');
            $table->json('lines_snapshot');
            $table->json('amounts_snapshot');
            $table->string('currency', 3)->default('PEN');
            $table->string('tax_calculation_status', 30)->default('pending_definition');
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('prepared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('last_modified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('prepared_at');
            $table->timestamp('last_modified_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
            $table->unique(['order_id', 'preparation_key'], 'electronic_documents_order_preparation_unique');
            $table->index(['order_id', 'status'], 'electronic_documents_order_status_idx');
        });

        Schema::create('electronic_document_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('electronic_document_id')->constrained('electronic_documents')->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type', 30);
            $table->unsignedInteger('version_from')->nullable();
            $table->unsignedInteger('version_to');
            $table->json('changed_fields')->nullable();
            $table->timestamp('created_at');
            $table->index(['electronic_document_id', 'id'], 'electronic_doc_events_doc_id_idx');
        });

        Schema::create('electronic_document_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('electronic_document_id')->constrained('electronic_documents')->restrictOnDelete();
            $table->foreignId('initiated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('attempt_number');
            $table->string('connection_type', 30);
            $table->string('environment', 20);
            $table->uuid('idempotency_key')->unique();
            $table->char('payload_hash', 64);
            $table->json('request_snapshot');
            $table->string('provider_reference', 120)->nullable();
            $table->string('status', 20);
            $table->string('response_code', 60)->nullable();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['electronic_document_id', 'attempt_number'], 'electronic_doc_attempt_number_unique');
            $table->index(['electronic_document_id', 'status'], 'electronic_doc_attempt_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('electronic_document_attempts');
        Schema::dropIfExists('electronic_document_events');
        Schema::dropIfExists('electronic_documents');
        Schema::dropIfExists('electronic_invoice_settings');
    }
};
