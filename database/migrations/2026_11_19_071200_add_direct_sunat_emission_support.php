<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('electronic_invoice_settings', function (Blueprint $table): void {
            $table->text('sunat_username_encrypted')->nullable();
            $table->text('sunat_password_encrypted')->nullable();
            $table->text('certificate_password_encrypted')->nullable();
            $table->string('certificate_path')->nullable();
            $table->string('certificate_fingerprint', 64)->nullable();
            $table->timestamp('certificate_expires_at')->nullable();
        });

        Schema::create('electronic_document_series', function (Blueprint $table): void {
            $table->id();
            $table->string('issuer_tax_id', 20);
            $table->string('environment', 20);
            $table->string('document_type', 20);
            $table->string('series', 10);
            $table->unsignedBigInteger('next_number')->default(1);
            $table->timestamps();
            $table->unique(['issuer_tax_id', 'environment', 'document_type'], 'electronic_document_series_identity_unique');
        });

        Schema::table('electronic_documents', function (Blueprint $table): void {
            $table->string('series', 10)->nullable();
            $table->unsignedBigInteger('document_number')->nullable();
            $table->string('result_code', 80)->nullable();
            $table->text('result_message')->nullable();
            $table->string('xml_path')->nullable();
            $table->string('zip_path')->nullable();
            $table->string('cdr_path')->nullable();
            $table->char('xml_hash', 64)->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->unique(['environment', 'document_type', 'series', 'document_number'], 'electronic_document_number_unique');
        });

        Schema::table('electronic_document_attempts', function (Blueprint $table): void {
            $table->string('response_path')->nullable();
            $table->string('ticket', 120)->nullable();
            $table->text('safe_observations')->nullable();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('electronic_documents') && DB::table('electronic_documents')->whereNotNull('document_number')->exists()) {
            throw new RuntimeException('No se puede retirar la numeración SUNAT mientras existan números asignados; archive y resuelva esos documentos antes.');
        }
        if (Schema::hasTable('electronic_document_attempts') && DB::table('electronic_document_attempts')->exists()) {
            throw new RuntimeException('No se puede retirar el soporte SUNAT mientras existan intentos; consérvelos para auditoría.');
        }

        Schema::table('electronic_document_attempts', function (Blueprint $table): void {
            $table->dropColumn(['response_path', 'ticket', 'safe_observations']);
        });
        Schema::table('electronic_documents', function (Blueprint $table): void {
            $table->dropUnique('electronic_document_number_unique');
            $table->dropColumn(['series', 'document_number', 'result_code', 'result_message', 'xml_path', 'zip_path', 'cdr_path', 'xml_hash', 'accepted_at']);
        });
        Schema::dropIfExists('electronic_document_series');
        Schema::table('electronic_invoice_settings', function (Blueprint $table): void {
            $table->dropColumn(['sunat_username_encrypted', 'sunat_password_encrypted', 'certificate_password_encrypted', 'certificate_path', 'certificate_fingerprint', 'certificate_expires_at']);
        });
    }
};
