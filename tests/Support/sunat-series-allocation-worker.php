<?php

use App\Services\ElectronicDocumentSeriesAllocator;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$script, $configPath, $expectedDatabase, $issuerTaxId, $resultPath, $readyPath] = $argv;
$connectionConfig = json_decode((string) file_get_contents($configPath), true, 512, JSON_THROW_ON_ERROR);
config(['database.connections.mysql' => $connectionConfig, 'database.default' => 'mysql']);
DB::purge('mysql');
$connection = DB::connection('mysql');
$effective = $connection->selectOne('SELECT DATABASE() AS database_name');
if ($connection->getDriverName() !== 'mysql' || $effective->database_name !== $expectedDatabase) {
    file_put_contents($resultPath, json_encode(['error' => 'database_target_mismatch'], JSON_THROW_ON_ERROR));
    exit(2);
}

file_put_contents($readyPath, (string) $connection->selectOne('SELECT CONNECTION_ID() AS id')->id);

try {
    $allocation = $connection->transaction(fn () => app(ElectronicDocumentSeriesAllocator::class)->reserve(
        $connection,
        $issuerTaxId,
        'testing',
        'factura',
        'F001'
    ));
    $allocation['connection_id'] = (int) $connection->selectOne('SELECT CONNECTION_ID() AS id')->id;
    file_put_contents($resultPath, json_encode($allocation, JSON_THROW_ON_ERROR));
} catch (Throwable $exception) {
    file_put_contents($resultPath, json_encode(['error' => get_class($exception)], JSON_THROW_ON_ERROR));
    exit(1);
}
