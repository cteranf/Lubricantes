<?php

use App\Models\PosCashSession;
use App\Models\PosRegister;
use App\Models\User;
use App\Services\PosCashSessionService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

$basePath = dirname(__DIR__, 2);
require $basePath.'/vendor/autoload.php';
$app = require $basePath.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$script, $configPath, $action, $mode, $registerId, $cashierId, $amount, $key, $sessionId, $reason, $readyPath, $startedPath, $startPath, $releasePath, $resultPath] = $argv;
$connection = json_decode(file_get_contents($configPath), true, flags: JSON_THROW_ON_ERROR);
config(['database.default' => 'mysql', 'database.connections.mysql' => $connection]);
DB::purge('mysql');

try {
    $expected = 'lubristore_pos_foundation_20261125_test';
    if (DB::connection('mysql')->getDriverName() !== 'mysql'
        || DB::connection('mysql')->selectOne('SELECT DATABASE() AS name')->name !== $expected) {
        throw new RuntimeException('POS worker database safety guard failed.');
    }
    $cashier = User::on('mysql')->findOrFail((int) $cashierId);
    $register = PosRegister::on('mysql')->findOrFail((int) $registerId);
    if ($mode === 'hold') {
        DB::connection('mysql')->beginTransaction();
        PosRegister::on('mysql')->whereKey((int) $registerId)->lockForUpdate()->firstOrFail();
        if ($action === 'close') {
            PosCashSession::on('mysql')->whereKey((int) $sessionId)->lockForUpdate()->firstOrFail();
        }
    }
    file_put_contents($readyPath, json_encode([
        'connection_id' => (int) DB::connection('mysql')->selectOne('SELECT CONNECTION_ID() AS id')->id,
    ], JSON_THROW_ON_ERROR), LOCK_EX);
    if ($startPath !== '-') {
        waitFor($startPath);
    }
    file_put_contents($startedPath, 'started', LOCK_EX);
    if ($mode === 'hold') {
        waitFor($releasePath);
    }

    $service = app(PosCashSessionService::class);
    if ($action === 'open') {
        $session = $service->open($register, $cashier, $amount, $key);
    } else {
        $session = $service->close(PosCashSession::on('mysql')->findOrFail((int) $sessionId), $cashier, $amount, $reason === '' ? null : $reason, $key);
    }
    if ($mode === 'hold') {
        DB::connection('mysql')->commit();
    }
    file_put_contents($resultPath, json_encode(['ok' => true, 'session_id' => $session->id], JSON_THROW_ON_ERROR), LOCK_EX);
} catch (ConflictHttpException $exception) {
    if (DB::connection('mysql')->transactionLevel() > 0) {
        DB::connection('mysql')->rollBack();
    }
    file_put_contents($resultPath, json_encode(['ok' => false, 'http_status' => $exception->getStatusCode()], JSON_THROW_ON_ERROR), LOCK_EX);
} catch (Throwable) {
    if (DB::connection('mysql')->transactionLevel() > 0) {
        DB::connection('mysql')->rollBack();
    }
    file_put_contents($resultPath, json_encode(['ok' => false, 'http_status' => 500], JSON_THROW_ON_ERROR), LOCK_EX);
    exit(1);
}

function waitFor(string $path): void
{
    $deadline = microtime(true) + 20;
    while (! is_file($path) && microtime(true) < $deadline) {
        usleep(20_000);
    }
    if (! is_file($path)) {
        throw new RuntimeException('POS worker barrier timed out.');
    }
}
