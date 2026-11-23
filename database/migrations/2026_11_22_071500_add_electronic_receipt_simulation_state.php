<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('electronic_receipt_summaries', function (Blueprint $table): void {
            $table->string('communication_status', 32)->default('not_started');
            $table->string('local_simulation_id', 80)->nullable()->unique();
            $table->string('external_ticket', 120)->nullable();
            $table->char('response_hash', 64)->nullable();
        });
        Schema::table('electronic_receipt_summary_attempts', function (Blueprint $table): void {
            $table->string('communication_status', 32)->default('not_started');
            $table->string('local_simulation_id', 80)->nullable()->unique();
            $table->string('external_ticket', 120)->nullable();
            $table->char('response_hash', 64)->nullable();
            $table->unsignedInteger('query_count')->default(0);
        });

        if (Schema::hasColumn('electronic_receipt_summaries', 'ticket')) {
            DB::table('electronic_receipt_summaries')->where('ticket', 'like', 'LOCAL-SIM-%')
                ->update(['local_simulation_id' => DB::raw('ticket')]);
            DB::table('electronic_receipt_summaries')->whereNotNull('ticket')->where('ticket', 'not like', 'LOCAL-SIM-%')
                ->update(['external_ticket' => DB::raw('ticket')]);
        }
        if (Schema::hasColumn('electronic_receipt_summary_attempts', 'ticket')) {
            DB::table('electronic_receipt_summary_attempts')->where('ticket', 'like', 'LOCAL-SIM-%')
                ->update(['local_simulation_id' => DB::raw('ticket')]);
            DB::table('electronic_receipt_summary_attempts')->whereNotNull('ticket')->where('ticket', 'not like', 'LOCAL-SIM-%')
                ->update(['external_ticket' => DB::raw('ticket')]);
        }
    }

    public function down(): void
    {
        DB::table('electronic_receipt_summaries')->whereNull('ticket')->where(function ($query): void {
            $query->whereNotNull('local_simulation_id')->orWhereNotNull('external_ticket');
        })->update(['ticket' => DB::raw('COALESCE(local_simulation_id, external_ticket)')]);
        DB::table('electronic_receipt_summary_attempts')->whereNull('ticket')->where(function ($query): void {
            $query->whereNotNull('local_simulation_id')->orWhereNotNull('external_ticket');
        })->update(['ticket' => DB::raw('COALESCE(local_simulation_id, external_ticket)')]);
        Schema::table('electronic_receipt_summary_attempts', function (Blueprint $table): void {
            $table->dropUnique(['local_simulation_id']);
            $table->dropColumn(['communication_status', 'local_simulation_id', 'external_ticket', 'response_hash', 'query_count']);
        });
        Schema::table('electronic_receipt_summaries', function (Blueprint $table): void {
            $table->dropUnique(['local_simulation_id']);
            $table->dropColumn(['communication_status', 'local_simulation_id', 'external_ticket', 'response_hash']);
        });
    }
};
