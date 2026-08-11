<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $defaults = DB::table('warehouses')->where('is_default', true)->orderBy('id')->get(['id', 'code']);

        if ($defaults->count() > 1) {
            throw new RuntimeException(
                'Existen varios almacenes predeterminados ('.$defaults->pluck('code')->implode(', ').'). '.
                'Defina manualmente cual vende por web antes de ejecutar esta migracion.'
            );
        }

        Schema::table('warehouses', function (Blueprint $table) {
            $table->unsignedTinyInteger('default_guard')->nullable()->after('is_default');
        });

        if ($defaults->count() === 1) {
            DB::table('warehouses')->where('id', $defaults->first()->id)->update(['default_guard' => 1]);
        }

        Schema::table('warehouses', function (Blueprint $table) {
            $table->unique('default_guard', 'warehouses_single_web_default_guard_unique');
        });
    }

    public function down(): void
    {
        Schema::table('warehouses', function (Blueprint $table) {
            $table->dropUnique('warehouses_single_web_default_guard_unique');
            $table->dropColumn('default_guard');
        });
    }
};
