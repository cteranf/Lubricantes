<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('electronic_receipt_summaries', function (Blueprint $table): void {
            $table->id();
            $table->string('issuer_tax_id', 20);
            $table->string('environment', 20);
            $table->date('issue_date');
            $table->unsignedInteger('sequence');
            $table->string('document_id', 24)->unique();
            $table->string('status', 32)->default('queued');
            $table->unsignedSmallInteger('document_count');
            $table->json('snapshot');
            $table->string('xml_path')->nullable();
            $table->string('zip_path')->nullable();
            $table->char('xml_hash', 64)->nullable();
            $table->string('ticket', 120)->nullable();
            $table->string('result_code', 80)->nullable();
            $table->text('result_message')->nullable();
            $table->string('response_path')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at');
            $table->timestamps();
            $table->index(['environment', 'issue_date', 'status'], 'receipt_summary_date_status_idx');
        });

        Schema::create('electronic_receipt_summary_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('electronic_receipt_summary_id');
            $table->unsignedBigInteger('electronic_document_id');
            $table->unsignedInteger('line_number');
            $table->string('result_code', 80)->nullable();
            $table->text('result_message')->nullable();
            $table->string('result_status', 32)->nullable();
            $table->timestamps();
            $table->foreign('electronic_receipt_summary_id', 'receipt_summary_items_summary_fk')->references('id')->on('electronic_receipt_summaries')->restrictOnDelete();
            $table->foreign('electronic_document_id', 'receipt_summary_items_document_fk')->references('id')->on('electronic_documents')->restrictOnDelete();
            $table->unique('electronic_document_id', 'receipt_summary_item_document_unique');
            $table->unique(['electronic_receipt_summary_id', 'line_number'], 'receipt_summary_line_unique');
        });

        Schema::create('electronic_receipt_summary_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('electronic_receipt_summary_id');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('event_type', 40);
            $table->json('details')->nullable();
            $table->timestamp('created_at');
            $table->foreign('electronic_receipt_summary_id', 'receipt_summary_events_summary_fk')->references('id')->on('electronic_receipt_summaries')->restrictOnDelete();
            $table->foreign('actor_id', 'receipt_summary_events_actor_fk')->references('id')->on('users')->nullOnDelete();
            $table->index(['electronic_receipt_summary_id', 'id'], 'receipt_summary_event_order_idx');
        });

        Schema::create('electronic_receipt_summary_attempts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('electronic_receipt_summary_id');
            $table->unsignedInteger('attempt_number');
            $table->uuid('idempotency_key')->unique();
            $table->char('payload_hash', 64);
            $table->json('request_snapshot');
            $table->string('status', 32);
            $table->string('ticket', 120)->nullable();
            $table->string('response_path')->nullable();
            $table->string('response_code', 80)->nullable();
            $table->text('safe_observations')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->foreign('electronic_receipt_summary_id', 'receipt_summary_attempts_summary_fk')->references('id')->on('electronic_receipt_summaries')->restrictOnDelete();
            $table->unique(['electronic_receipt_summary_id', 'attempt_number'], 'receipt_summary_attempt_number_unique');
        });

        Schema::create('electronic_receipt_summary_outbox', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('electronic_receipt_summary_attempt_id');
            $table->timestamp('published_at')->nullable()->index();
            $table->timestamps();
            $table->foreign('electronic_receipt_summary_attempt_id', 'receipt_summary_outbox_attempt_fk')->references('id')->on('electronic_receipt_summary_attempts')->restrictOnDelete();
            $table->unique('electronic_receipt_summary_attempt_id', 'receipt_summary_outbox_attempt_unique');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('electronic_receipt_summaries')
            && DB::table('electronic_receipt_summaries')->exists()) {
            throw new RuntimeException('No se puede retirar el esquema mientras existan resúmenes confirmados; se perderían sus documentos congelados, tickets y resultados.');
        }

        Schema::dropIfExists('electronic_receipt_summary_outbox');
        Schema::dropIfExists('electronic_receipt_summary_attempts');
        Schema::dropIfExists('electronic_receipt_summary_events');
        Schema::dropIfExists('electronic_receipt_summary_items');
        Schema::dropIfExists('electronic_receipt_summaries');
    }
};
