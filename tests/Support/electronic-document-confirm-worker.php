<?php

use App\Models\ElectronicDocument;
use App\Models\User;
use App\Services\ElectronicDocumentEmissionService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$script, $configPath, $expectedDatabase, $documentId, $actorId, $resultPath, $readyPath] = $argv;
$connectionConfig = json_decode((string) file_get_contents($configPath), true, 512, JSON_THROW_ON_ERROR);
config([
    'database.connections.mysql' => $connectionConfig,
    'database.default' => 'mysql',
    'queue.default' => 'database',
    'queue.connections.database.connection' => 'mysql',
    'electronic_invoicing.enabled' => true,
    'electronic_invoicing.sunat.xsd_directory' => $root.'/tests/Fixtures/OasisUBL21',
    'electronic_invoicing.sunat.invoice_xsd' => $root.'/tests/Fixtures/OasisUBL21/xsd/maindoc/UBL-Invoice-2.1.xsd',
]);
DB::purge('mysql');
$connection = DB::connection('mysql');
$effective = $connection->selectOne('SELECT DATABASE() AS database_name');
if ($connection->getDriverName() !== 'mysql' || $effective->database_name !== $expectedDatabase) {
    file_put_contents($resultPath, json_encode(['error' => 'database_target_mismatch'], JSON_THROW_ON_ERROR));
    exit(2);
}

file_put_contents($readyPath, (string) $connection->selectOne('SELECT CONNECTION_ID() AS id')->id);

try {
    $document = ElectronicDocument::query()->findOrFail((int) $documentId);
    $actor = User::query()->findOrFail((int) $actorId);
    $saved = app(ElectronicDocumentEmissionService::class)->confirm($document, $actor, (int) $document->version);
    file_put_contents($resultPath, json_encode([
        'id' => (int) $saved->id,
        'status' => $saved->status,
        'series' => $saved->series,
        'document_number' => (int) $saved->document_number,
        'confirmed_by' => (int) $saved->confirmed_by,
        'confirmed_at' => $saved->confirmed_at?->toISOString(),
    ], JSON_THROW_ON_ERROR));
} catch (Throwable $exception) {
    file_put_contents($resultPath, json_encode(['error' => get_class($exception)], JSON_THROW_ON_ERROR));
    exit(1);
}
