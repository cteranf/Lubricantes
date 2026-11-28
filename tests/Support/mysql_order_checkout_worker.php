<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

$basePath = dirname(__DIR__, 2);
require $basePath.'/vendor/autoload.php';
$app = require $basePath.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$script, $configPath, $mode, $productId, $accountsPath, $label, $readyPath, $heldPath, $startedPath, $startPath, $releasePath, $resultPath, $accountIndex] = $argv;
$connection = json_decode(file_get_contents($configPath), true, flags: JSON_THROW_ON_ERROR);
$accounts = json_decode(file_get_contents($accountsPath), true, flags: JSON_THROW_ON_ERROR);
config(['database.default' => 'mysql', 'database.connections.mysql' => $connection]);
DB::purge('mysql');

try {
    $expected = 'lubristore_pos_foundation_20261125_test';
    if (DB::connection('mysql')->getDriverName() !== 'mysql'
        || DB::connection('mysql')->selectOne('SELECT DATABASE() AS name')->name !== $expected) {
        throw new RuntimeException('Checkout worker database safety guard failed.');
    }
    if ($mode === 'hold-and-checkout') {
        DB::listen(function ($query) use ($heldPath, $releasePath) {
            if (preg_match('/^select .* from `products` .* for update$/i', trim($query->sql)) === 1) {
                file_put_contents($heldPath, 'locks-acquired', LOCK_EX);
                waitFor($releasePath);
            }
        });
    }
    file_put_contents($readyPath, (string) DB::connection('mysql')->selectOne('SELECT CONNECTION_ID() AS id')->id, LOCK_EX);
    if ($startPath !== '-') {
        waitFor($startPath);
    }
    file_put_contents($startedPath, 'started', LOCK_EX);

    $account = $accounts[(int) $accountIndex];
    $request = Request::create('/api/v1/orders', 'POST', [], [], [], [
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_AUTHORIZATION' => 'Bearer '.$account['token'],
        'CONTENT_TYPE' => 'application/json',
    ], json_encode($account['payload'], JSON_THROW_ON_ERROR));
    $response = $app->make(Illuminate\Contracts\Http\Kernel::class)->handle($request);
    $status = $response->getStatusCode();
    $response->sendHeaders();
    $responseData = json_decode($response->getContent(), true);
    $app->make(Illuminate\Contracts\Http\Kernel::class)->terminate($request, $response);
    file_put_contents($resultPath, json_encode([
        'status' => $status,
        'order_id' => $responseData['id'] ?? null,
    ], JSON_THROW_ON_ERROR), LOCK_EX);
} catch (Throwable) {
    if (DB::connection('mysql')->transactionLevel() > 0) {
        DB::connection('mysql')->rollBack();
    }
    file_put_contents($resultPath, json_encode(['status' => 500], JSON_THROW_ON_ERROR), LOCK_EX);
    exit(1);
}

function waitFor(string $path): void
{
    $deadline = microtime(true) + 20;
    while (! is_file($path) && microtime(true) < $deadline) {
        usleep(20_000);
    }
    if (! is_file($path)) {
        throw new RuntimeException('Checkout worker barrier timed out.');
    }
}
