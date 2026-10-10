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

$releasePathHash = hash('sha256', strtolower(str_replace('\\', '/', $release)));
$readyMessage = json_encode(['release_path_hash' => $releasePathHash]);
if (! is_string($readyMessage)
    || @file_put_contents($ready, $readyMessage, LOCK_EX) !== strlen($readyMessage)
    || ! is_file($ready)) {
    echo json_encode(['result' => 'ready_creation_failed', 'ok' => false, 'stage' => 'ready']);
    exit(2);
}

$released = false;
$deadline = microtime(true) + 20;

while (microtime(true) < $deadline) {
    clearstatcache(true, $release);

    $releaseContents = @file_get_contents($release);

    if ($releaseContents === 'release') {
        $released = true;
        break;
    }

    usleep(20_000);
}

if (! $released) {
    echo json_encode(['result' => 'release_timeout', 'ok' => false]);
    exit(2);
}

echo json_encode(['result' => 'success', 'ok' => true, 'release_observed' => true]);
exit(0);
