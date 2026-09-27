<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->foreignId('payment_submission_id')->nullable()->after('order_id');
            $table->foreign('payment_submission_id', 'pt_submission_fk')
                ->references('id')->on('payment_submissions')->restrictOnDelete();
            $table->unique('payment_submission_id', 'pt_submission_unique');
        });
    }

    public function down(): void
    {
        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->dropForeign('pt_submission_fk');
            $table->dropUnique('pt_submission_unique');
            $table->dropColumn('payment_submission_id');
        });
    }
};
