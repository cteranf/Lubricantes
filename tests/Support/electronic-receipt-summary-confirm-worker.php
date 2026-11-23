<?php

use App\Models\ElectronicReceiptSummary;
use App\Models\User;
use App\Services\ElectronicReceiptSummaryService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$script, $configPath, $expectedDatabase, $selectionJson, $actorId, $resultPath, $readyPath] = $argv;
$selection = json_decode((string) $selectionJson, true, 512, JSON_THROW_ON_ERROR);
$connectionConfig = json_decode((string) file_get_contents($configPath), true, 512, JSON_THROW_ON_ERROR);
config([
    'database.connections.mysql' => $connectionConfig,
    'database.default' => 'mysql',
    'queue.default' => 'database',
    'queue.connections.database.connection' => 'mysql',
    'electronic_invoicing.enabled' => true,
    'electronic_invoicing.sunat.xsd_directory' => $root.'/resources/sunat/xsd',
    'electronic_invoicing.sunat.summary_xsd' => $root.'/resources/sunat/xsd/Archivos XSD/2.0/maindoc/UBLPE-SummaryDocuments-1.0.xsd',
]);
DB::purge('mysql');
$connection = DB::connection('mysql');
$effective = $connection->selectOne('SELECT DATABASE() AS database_name, @@default_storage_engine AS engine_name');
if ($connection->getDriverName() !== 'mysql' || $effective->database_name !== $expectedDatabase
    || ! str_ends_with((string) $effective->database_name, '_test')
    || in_array($effective->database_name, ['lubricantes', 'lubristore_remote_payments_test'], true)
    || strtoupper((string) $effective->engine_name) !== 'INNODB') {
    file_put_contents($resultPath, json_encode(['error' => 'database_target_mismatch'], JSON_THROW_ON_ERROR));
    exit(2);
}

file_put_contents($readyPath, (string) $connection->selectOne('SELECT CONNECTION_ID() AS id')->id);

try {
    $actor = User::query()->findOrFail((int) $actorId);
    $saved = app(ElectronicReceiptSummaryService::class)->confirm($selection, $actor);
    file_put_contents($resultPath, json_encode([
        'id' => (int) $saved->id,
        'status' => $saved->status,
        'document_count' => (int) $saved->document_count,
        'summary_count' => ElectronicReceiptSummary::query()->count(),
    ], JSON_THROW_ON_ERROR));
} catch (Throwable $exception) {
    file_put_contents($resultPath, json_encode(['error' => get_class($exception)], JSON_THROW_ON_ERROR));
    exit(1);
}
