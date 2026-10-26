<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

function observerOption(string $name): string
{
    global $argv;
    $index = array_search($name, $argv, true);
    if ($index === false || ! isset($argv[$index + 1])) {
        throw new RuntimeException('Observer argument missing.');
    }

    return $argv[$index + 1];
}

function observerAtomicJson(string $path, array $data): void
{
    $temporary = dirname($path).DIRECTORY_SEPARATOR.'.'.basename($path).'.tmp-'.bin2hex(random_bytes(12));
    $handle = @fopen($temporary, 'x+b');
    if ($handle === false) {
        throw new RuntimeException('Observer marker temporary could not be opened.');
    }
    $published = false;
    $failure = null;
    try {
        $json = json_encode($data, JSON_THROW_ON_ERROR);
        $length = strlen($json);
        $written = 0;
        while ($written < $length) {
            $count = @fwrite($handle, substr($json, $written));
            if (! is_int($count) || $count < 1) {
                throw new RuntimeException('Observer marker write failed.');
            }
            $written += $count;
        }
        if (! @fflush($handle)) {
            throw new RuntimeException('Observer marker flush failed.');
        }
        $closed = @fclose($handle);
        $handle = null;
        if (! $closed) {
            throw new RuntimeException('Observer marker close failed.');
        }
        if (file_exists($path) || is_link($path)) {
            throw new RuntimeException('Observer marker destination already exists.');
        }
        if (! @rename($temporary, $path)) {
            throw new RuntimeException('Observer marker publication failed.');
        }
        $published = true;
    } catch (Throwable) {
        $failure = new RuntimeException('Observer marker publication failed.');
    } finally {
        if (is_resource($handle)) {
            $closed = @fclose($handle);
            $handle = null;
            if (! $closed && $failure === null) {
                $failure = new RuntimeException('Observer marker close failed.');
            }
        }
        if (! $published && (file_exists($temporary) || is_link($temporary))) {
            $removed = @unlink($temporary);
            if (! $removed && (file_exists($temporary) || is_link($temporary)) && $failure === null) {
                $failure = new RuntimeException('Observer marker temporary cleanup failed.');
            }
        }
    }
    if ($failure !== null) {
        throw $failure;
    }
}

function observerRelease(string $root): void
{
    $handle = @fopen($root.DIRECTORY_SEPARATOR.'release', 'x');
    if ($handle !== false) {
        fwrite($handle, '1');
        fclose($handle);
    }
}

$fixture = json_decode((string) file_get_contents(observerOption('--fixture')), true, 32, JSON_THROW_ON_ERROR);
$root = $fixture['marker_root'];
$resultPath = $root.DIRECTORY_SEPARATOR.'lock-observed.json';
$deadline = microtime(true) + 20;

try {
    if (! app()->environment('testing') || config('app.debug') !== false || config('database.default') !== 'mysql') {
        throw new RuntimeException('Observer preflight failed.');
    }
    $pdo = DB::connection()->getPdo();
    $effective = $pdo->query('SELECT DATABASE() AS database_name')->fetch(PDO::FETCH_ASSOC)['database_name'] ?? null;
    if (! is_string($effective) || preg_match('/^[A-Za-z][A-Za-z0-9_]*_test$/D', $effective) !== 1
        || strcasecmp($effective, 'lubricantes') === 0
        || $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
        throw new RuntimeException('Observer effective database rejected.');
    }
    $observed = false;
    while (microtime(true) < $deadline) {
        $rows = $pdo->query('SELECT reqt.PROCESSLIST_ID AS requesting_id, blockt.PROCESSLIST_ID AS blocking_id, req.OBJECT_SCHEMA AS object_schema, req.OBJECT_NAME AS object_name FROM performance_schema.data_lock_waits w JOIN performance_schema.data_locks req ON req.ENGINE = w.ENGINE AND req.ENGINE_LOCK_ID = w.REQUESTING_ENGINE_LOCK_ID JOIN performance_schema.data_locks block ON block.ENGINE = w.ENGINE AND block.ENGINE_LOCK_ID = w.BLOCKING_ENGINE_LOCK_ID JOIN performance_schema.threads reqt ON reqt.THREAD_ID = req.THREAD_ID JOIN performance_schema.threads blockt ON blockt.THREAD_ID = block.THREAD_ID')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            if ((int) $row['requesting_id'] !== (int) $fixture['requesting_connection_id']
                || (int) $row['blocking_id'] !== (int) $fixture['blocking_connection_id']) {
                continue;
            }
            $expected = $row['object_name'] === 'orders' && $row['object_schema'] === $effective;
            observerAtomicJson($resultPath, [
                'observed' => $expected,
                'requesting_connection_id' => (int) $row['requesting_id'],
                'blocking_connection_id' => (int) $row['blocking_id'],
                'table' => (string) $row['object_name'],
                'schema_matches' => $row['object_schema'] === $effective,
            ]);
            observerRelease($root);
            $observed = $expected;
            break 2;
        }
        usleep(20000);
    }
    if (! $observed) {
        observerAtomicJson($resultPath, ['observed' => false, 'requesting_connection_id' => 0, 'blocking_connection_id' => 0, 'table' => '', 'schema_matches' => false]);
        observerRelease($root);
        exit(2);
    }
} catch (Throwable) {
    try {
        observerAtomicJson($resultPath, ['observed' => false, 'requesting_connection_id' => 0, 'blocking_connection_id' => 0, 'table' => '', 'schema_matches' => false]);
        observerRelease($root);
    } catch (Throwable) {
    }
    exit(1);
}
