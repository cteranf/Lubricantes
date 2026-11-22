<?php

use App\Models\InventoryReceipt;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryReceiptService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

$basePath = dirname(__DIR__, 2);
require $basePath.'/vendor/autoload.php';
$app = require $basePath.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$script, $configPath, $mode, $receiptId, $version, $actorId, $readyPath, $startPath, $releasePath, $resultPath] = $argv;
$connectionConfig = json_decode(file_get_contents($configPath), true, flags: JSON_THROW_ON_ERROR);
config(['database.default' => 'mysql', 'database.connections.mysql' => $connectionConfig]);
DB::purge('mysql');

try {
    $expectedDatabase = getenv('LARAVEL13_MYSQL_TEST_DATABASE');
    if (! is_string($expectedDatabase)
        || preg_match('/\Alubristore_php85_laravel13_[a-z0-9_]+\z/', $expectedDatabase) !== 1
        || DB::connection('mysql')->getDriverName() !== 'mysql'
        || DB::connection('mysql')->selectOne('SELECT DATABASE() AS database_name')->database_name !== $expectedDatabase) {
        throw new RuntimeException('Database safety guard failed.');
    }

    if ($mode === 'hold-header' || $mode === 'hold-warehouses') {
        DB::connection('mysql')->beginTransaction();
        if ($mode === 'hold-header') {
            InventoryReceipt::on('mysql')->whereKey((int) $receiptId)->lockForUpdate()->firstOrFail();
        } else {
            Warehouse::on('mysql')->orderBy('id')->lockForUpdate()->get(['id']);
        }
        file_put_contents($readyPath, (string) DB::connection('mysql')->selectOne('SELECT CONNECTION_ID() AS connection_id')->connection_id);
        waitForSignal($releasePath);
        $confirmed = app(InventoryReceiptService::class)->confirm((int) $receiptId, (int) $version, User::on('mysql')->findOrFail((int) $actorId));
        DB::connection('mysql')->commit();
    } else {
        file_put_contents($readyPath, (string) DB::connection('mysql')->selectOne('SELECT CONNECTION_ID() AS connection_id')->connection_id);
        if ($startPath !== '-') {
            waitForSignal($startPath);
        }
        $confirmed = app(InventoryReceiptService::class)->confirm((int) $receiptId, (int) $version, User::on('mysql')->findOrFail((int) $actorId));
    }

    file_put_contents($resultPath, json_encode([
        'ok' => true,
        'confirmed_by' => $confirmed->confirmed_by,
        'confirmed_at' => $confirmed->confirmed_at?->toISOString(),
        'version' => $confirmed->version,
    ], JSON_THROW_ON_ERROR));
} catch (Throwable) {
    if (DB::connection('mysql')->transactionLevel() > 0) {
        DB::connection('mysql')->rollBack();
    }
    file_put_contents($resultPath, json_encode(['ok' => false], JSON_THROW_ON_ERROR));
    exit(1);
}

function waitForSignal(string $path): void
{
    $deadline = microtime(true) + 20;
    while (! is_file($path) && microtime(true) < $deadline) {
        usleep(20_000);
    }
    if (! is_file($path)) {
        throw new RuntimeException('Barrier timed out.');
    }
}
