<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const STATUSES = ['pending', 'approved', 'rejected', 'refund_pending', 'refunded'];

    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            $this->rebuildSqliteEnum(self::STATUSES);

            return;
        }

        DB::statement("ALTER TABLE orders MODIFY payment_status ENUM('pending', 'approved', 'rejected', 'refund_pending', 'refunded') NOT NULL DEFAULT 'pending'");
    }

    public function down(): void
    {
        if (DB::table('orders')->where('payment_status', 'refund_pending')->exists()) {
            throw new \RuntimeException('No se puede retirar refund_pending mientras existan pedidos con ese estado.');
        }

        if (DB::connection()->getDriverName() === 'sqlite') {
            $this->rebuildSqliteEnum(['pending', 'approved', 'rejected', 'refunded']);

            return;
        }

        DB::statement("ALTER TABLE orders MODIFY payment_status ENUM('pending', 'approved', 'rejected', 'refunded') NOT NULL DEFAULT 'pending'");
    }

    private function rebuildSqliteEnum(array $statuses): void
    {
        $definition = DB::selectOne("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'orders'")?->sql;
        if (! is_string($definition)) {
            throw new \RuntimeException('No se encontró la definición SQLite de orders.');
        }

        $quotedStatuses = implode(', ', array_map(fn (string $status) => "'{$status}'", $statuses));
        $updated = preg_replace(
            '/check\s*\(\s*"payment_status"\s+in\s*\([^)]*\)\s*\)/i',
            'check ("payment_status" in ('.$quotedStatuses.'))',
            $definition,
            1
        );
        if (! is_string($updated) || $updated === $definition) {
            throw new \RuntimeException('No se pudo actualizar de forma segura el CHECK SQLite de payment_status.');
        }

        $indexes = DB::select("SELECT sql FROM sqlite_master WHERE type = 'index' AND tbl_name = 'orders' AND sql IS NOT NULL");
        $columns = collect(DB::select("PRAGMA table_info('orders')"))->pluck('name')->map(fn (string $name) => '"'.$name.'"')->implode(', ');

        DB::statement('PRAGMA foreign_keys = OFF');
        DB::statement('PRAGMA legacy_alter_table = ON');
        try {
            DB::statement('ALTER TABLE orders RENAME TO orders_2b4_old');
            DB::unprepared($updated);
            DB::statement("INSERT INTO orders ({$columns}) SELECT {$columns} FROM orders_2b4_old");
            DB::statement('DROP TABLE orders_2b4_old');
            foreach ($indexes as $index) {
                DB::unprepared($index->sql);
            }
        } finally {
            DB::statement('PRAGMA legacy_alter_table = OFF');
            DB::statement('PRAGMA foreign_keys = ON');
        }

        if (DB::select('PRAGMA foreign_key_check') !== []) {
            throw new \RuntimeException('La reconstrucción SQLite de orders dejó claves foráneas inválidas.');
        }
    }
};
