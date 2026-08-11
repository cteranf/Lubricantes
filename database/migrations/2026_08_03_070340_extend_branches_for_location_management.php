<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->string('reference', 500)->nullable()->after('department');
            $table->string('business_hours', 500)->nullable()->after('email');
            $table->text('pickup_instructions')->nullable()->after('business_hours');
            $table->boolean('allows_pickup')->default(false)->after('pickup_instructions');
            $table->boolean('serves_public')->default(false)->after('allows_pickup');
            $table->boolean('is_main')->default(false)->index()->after('serves_public');
            // MySQL permite varios NULL y solo un valor 1: garantiza una principal sin columnas generadas.
            $table->unsignedTinyInteger('main_guard')->nullable()->after('is_main');
        });

        DB::transaction(function () {
            $branches = DB::table('branches')->orderBy('id')->lockForUpdate()->get(['id', 'is_active']);

            // Solo es seguro inferir la principal cuando existe una única sede histórica.
            if ($branches->count() === 1 && $branches->first()->is_active) {
                DB::table('branches')->where('id', $branches->first()->id)->update([
                    'is_main' => true,
                    'main_guard' => 1,
                ]);
            }
        });

        Schema::table('branches', function (Blueprint $table) {
            $table->unique('main_guard', 'branches_single_main_guard_unique');
        });
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->dropUnique('branches_single_main_guard_unique');
            $table->dropIndex(['is_main']);
            $table->dropColumn([
                'reference',
                'business_hours',
                'pickup_instructions',
                'allows_pickup',
                'serves_public',
                'is_main',
                'main_guard',
            ]);
        });
    }
};
