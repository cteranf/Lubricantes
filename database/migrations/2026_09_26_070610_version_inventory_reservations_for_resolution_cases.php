<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_reservations', function (Blueprint $table) {
            $table->unsignedInteger('reservation_sequence')->default(1)->after('order_item_id');
            $table->unsignedBigInteger('resolution_case_id')->nullable()->after('order_id');
        });

        Schema::table('inventory_reservations', function (Blueprint $table) {
            $table->unique(['order_item_id', 'reservation_sequence'], 'ir_item_sequence_unique');
            // The composite key keeps order_item_id indexed while the legacy
            // unique key is removed; MySQL requires that index for its FK.
            $table->dropUnique(['order_item_id']);
            $table->index('resolution_case_id', 'ir_resolution_case_idx');
            $table->foreign('resolution_case_id', 'ir_resolution_case_fk')
                ->references('id')->on('payment_resolution_cases')->restrictOnDelete();
        });

        if (DB::connection()->getDriverName() === 'sqlite') {
            $this->rebuildSqliteResolutionForeignKey(true);
        }
    }

    public function down(): void
    {
        if (DB::table('inventory_reservations')
            ->where('reservation_sequence', '>', 1)
            ->exists()) {
            throw new \RuntimeException('No se puede restaurar el UNIQUE por ítem mientras existan reservas versionadas.');
        }

        if (DB::connection()->getDriverName() === 'sqlite') {
            $this->rebuildSqliteResolutionForeignKey(false);

            Schema::table('inventory_reservations', function (Blueprint $table) {
                $table->dropIndex('ir_resolution_case_idx');
                $table->unique('order_item_id');
                $table->dropUnique('ir_item_sequence_unique');
                $table->dropColumn(['reservation_sequence', 'resolution_case_id']);
            });

            return;
        }

        Schema::table('inventory_reservations', function (Blueprint $table) {
            $table->dropForeign('ir_resolution_case_fk');
            $table->dropIndex('ir_resolution_case_idx');
            $table->unique('order_item_id');
            $table->dropUnique('ir_item_sequence_unique');
            $table->dropColumn(['reservation_sequence', 'resolution_case_id']);
        });
    }

    private function rebuildSqliteResolutionForeignKey(bool $add): void
    {
        $definition = DB::selectOne("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'inventory_reservations'")?->sql;
        if (! is_string($definition)) {
            throw new \RuntimeException('No se encontró la definición SQLite de inventory_reservations.');
        }

        $foreignKey = 'foreign key("resolution_case_id") references "payment_resolution_cases"("id") on delete restrict';
        if ($add) {
            if (str_contains(strtolower($definition), 'foreign key("resolution_case_id")')) {
                return;
            }
            $updated = preg_replace('/\)\s*$/', ', '.$foreignKey.')', $definition, 1);
        } else {
            $updated = preg_replace('/,\s*foreign key\("resolution_case_id"\) references "payment_resolution_cases"\("id"\) on delete restrict/i', '', $definition, 1);
        }
        if (! is_string($updated) || $updated === $definition) {
            throw new \RuntimeException('No se pudo actualizar de forma segura la FK SQLite de resolution_case_id.');
        }

        $indexes = DB::select("SELECT sql FROM sqlite_master WHERE type = 'index' AND tbl_name = 'inventory_reservations' AND sql IS NOT NULL");
        $columns = collect(DB::select("PRAGMA table_info('inventory_reservations')"))->pluck('name')->map(fn (string $name) => '"'.$name.'"')->implode(', ');

        DB::statement('PRAGMA foreign_keys = OFF');
        DB::statement('PRAGMA legacy_alter_table = ON');
        try {
            DB::statement('ALTER TABLE inventory_reservations RENAME TO inventory_reservations_2b4_old');
            DB::unprepared($updated);
            DB::statement("INSERT INTO inventory_reservations ({$columns}) SELECT {$columns} FROM inventory_reservations_2b4_old");
            DB::statement('DROP TABLE inventory_reservations_2b4_old');
            foreach ($indexes as $index) {
                DB::unprepared($index->sql);
            }
        } finally {
            DB::statement('PRAGMA legacy_alter_table = OFF');
            DB::statement('PRAGMA foreign_keys = ON');
        }

        if (DB::select('PRAGMA foreign_key_check') !== []) {
            throw new \RuntimeException('La reconstrucción SQLite de inventory_reservations dejó claves foráneas inválidas.');
        }
    }
};
