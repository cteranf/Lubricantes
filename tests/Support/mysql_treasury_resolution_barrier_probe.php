<?php

$arguments = array_slice($argv, 1);
[$submissionId, $actorId, $ready, $release, $action] = $arguments + [null, null, null, null, null];

if (
    count($arguments) !== 5
    || ! is_numeric($submissionId)
    || ! is_numeric($actorId)
    || $action !== 'barrier_probe'
    || ! is_dir(dirname($ready))
    || ! is_writable(dirname($ready))
    || dirname($ready) !== dirname($release)
    || ! in_array(basename($ready), ['ready-first', 'ready-second'], true)
    || basename($release) !== 'release'
) {
    echo json_encode(['result' => 'worker_error', 'ok' => false]);
    exit(2);
}

if (@file_put_contents($ready, 'ready', LOCK_EX) !== 5 || ! is_file($ready)) {
    echo json_encode(['result' => 'ready_creation_failed', 'ok' => false, 'stage' => 'ready']);
    exit(2);
}

$deadline = microtime(true) + 5;
while (microtime(true) < $deadline) {
    clearstatcache(true, $release);
    if (is_file($release) && @file_get_contents($release) === 'release') {
        break;
    }
    usleep(25_000);
}

clearstatcache(true, $release);
if (! is_file($release) || @file_get_contents($release) !== 'release') {
    echo json_encode(['result' => 'release_timeout', 'ok' => false]);
    exit(2);
}

echo json_encode(['result' => 'success', 'ok' => true]);
exit(0);
