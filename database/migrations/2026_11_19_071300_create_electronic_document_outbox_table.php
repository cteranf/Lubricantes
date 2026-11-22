<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('electronic_document_outbox', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('electronic_document_attempt_id')->unique();
            $table->foreign('electronic_document_attempt_id', 'electronic_doc_outbox_attempt_fk')
                ->references('id')->on('electronic_document_attempts')->restrictOnDelete();
            $table->timestamp('published_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('electronic_document_outbox')
            && Schema::hasColumn('electronic_document_outbox', 'published_at')
            && DB::table('electronic_document_outbox')->whereNotNull('published_at')->exists()) {
            throw new RuntimeException('No se puede retirar el outbox SUNAT con trabajos publicados; conserve la trazabilidad.');
        }
        Schema::dropIfExists('electronic_document_outbox');
    }
};
