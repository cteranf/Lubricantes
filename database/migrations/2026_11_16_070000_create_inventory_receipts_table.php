<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('inventory_receipts', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 40)->unique();
            $table->string('external_reference', 100)->nullable();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->string('status', 20)->default('draft');
            $table->text('notes')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();

            $table->index(['warehouse_id', 'status', 'created_at'], 'receipt_warehouse_status_created_index');
            $table->index(['status', 'created_at'], 'receipt_status_created_index');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('inventory_receipts')
            && Schema::hasTable('inventory_receipt_lines')
            && Schema::hasTable('inventory_movements')
            && \Illuminate\Support\Facades\DB::table('inventory_receipt_lines as lines')
                ->join('inventory_movements as movements', function ($join) {
                    $join->on('movements.reference_id', '=', 'lines.id')
                        ->where('movements.reference_type', '=', 'inventory_receipt_line');
                })->exists()) {
            throw new RuntimeException('No se puede retirar el esquema: existen movimientos que dependen de líneas de recepción.');
        }

        if (Schema::hasTable('inventory_receipts')
            && \Illuminate\Support\Facades\DB::table('inventory_receipts')->where('status', 'confirmed')->exists()) {
            throw new RuntimeException('No se puede retirar el esquema mientras existan recepciones confirmadas.');
        }

        Schema::dropIfExists('inventory_receipts');
    }
};
