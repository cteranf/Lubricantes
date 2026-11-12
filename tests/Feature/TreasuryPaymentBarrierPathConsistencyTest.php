<?php

namespace Tests\Feature;

use Tests\TestCase;

class TreasuryPaymentBarrierPathConsistencyTest extends TestCase
{
    public function test_reassignment_barrier_paths_are_consistent_without_a_database(): void
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'lubristore-reassignment-race-'.uniqid('', true);
        $firstReady = $directory.DIRECTORY_SEPARATOR.'ready-first';
        $secondReady = $directory.DIRECTORY_SEPARATOR.'ready-second';
        $release = $directory.DIRECTORY_SEPARATOR.'release';
        $probe = base_path('tests/Support/mysql_treasury_reassignment_barrier_probe.php');
        $workers = [];

        try {
            $this->assertTrue(mkdir($directory) || is_dir($directory));
            $this->assertSame('mysql_treasury_reassignment_barrier_probe.php', basename($probe));
            $this->assertNotSame('mysql_treasury_reassignment_worker.php', basename($probe));
            $this->assertTrue(is_dir($directory));
            $this->assertTrue(is_writable($directory));
            foreach ([$firstReady, $secondReady] as $ready) {
                $environment = array_merge(getenv() ?: [], ['APP_ENV' => 'testing']);
                $process = proc_open([PHP_BINARY, $probe, '1', '1', $ready, $release, 'barrier_probe'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, base_path(), $environment);
                $this->assertIsResource($process);
                fclose($pipes[0]);
                stream_set_blocking($pipes[1], false);
                stream_set_blocking($pipes[2], false);
                $workers[] = ['process' => $process, 'stdout' => $pipes[1], 'stderr' => $pipes[2]];
            }

            $readyDeadline = microtime(true) + 5;
            while (microtime(true) < $readyDeadline) {
                clearstatcache(true, $firstReady);
                clearstatcache(true, $secondReady);
                if (is_file($firstReady) && is_file($secondReady)) {
                    break;
                }
                usleep(25_000);
            }
            clearstatcache(true, $firstReady);
            clearstatcache(true, $secondReady);
            $this->assertTrue(is_file($firstReady));
            $this->assertTrue(is_file($secondReady));
            $this->assertSame(7, file_put_contents($release, 'release', LOCK_EX));
            clearstatcache(true, $release);
            $this->assertTrue(is_file($release));
            $this->assertSame('release', file_get_contents($release));

            foreach ($workers as $worker) {
                $deadline = microtime(true) + 5;
                $workerExitCode = null;
                while (microtime(true) < $deadline) {
                    $status = proc_get_status($worker['process']);
                    if (! $status['running']) {
                        $workerExitCode = $status['exitcode'] >= 0 ? $status['exitcode'] : null;
                        break;
                    }
                    usleep(25_000);
                }
                $stdout = stream_get_contents($worker['stdout']) ?: '';
                $stderr = stream_get_contents($worker['stderr']) ?: '';
                fclose($worker['stdout']);
                fclose($worker['stderr']);
                $exitCode = $workerExitCode ?? proc_close($worker['process']);
                if ($workerExitCode !== null) {
                    proc_close($worker['process']);
                }
                $this->assertSame(0, $exitCode, $stderr);
                $this->assertSame(['result' => 'success', 'ok' => true, 'release_observed' => true], json_decode($stdout, true));
            }
            $workers = [];
        } finally {
            foreach ($workers as $worker) {
                @proc_terminate($worker['process']);
                @fclose($worker['stdout']);
                @fclose($worker['stderr']);
                @proc_close($worker['process']);
            }
            @unlink($firstReady);
            @unlink($secondReady);
            @unlink($release);
            @rmdir($directory);
        }

        $this->assertDirectoryDoesNotExist($directory);
    }

    public function test_resolution_barrier_paths_are_consistent_without_a_database(): void
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'lubristore-resolution-race-'.uniqid('', true);
        $firstReady = $directory.DIRECTORY_SEPARATOR.'ready-first';
        $secondReady = $directory.DIRECTORY_SEPARATOR.'ready-second';
        $release = $directory.DIRECTORY_SEPARATOR.'release';
        $probe = base_path('tests/Support/mysql_treasury_resolution_barrier_probe.php');
        $workers = [];

        try {
            $this->assertTrue(mkdir($directory) || is_dir($directory));
            $this->assertSame('mysql_treasury_resolution_barrier_probe.php', basename($probe));
            $this->assertNotSame('mysql_treasury_resolution_worker.php', basename($probe));
            $this->assertTrue(is_dir($directory));
            $this->assertTrue(is_writable($directory));
            foreach ([$firstReady, $secondReady] as $ready) {
                $environment = array_merge(getenv() ?: [], ['APP_ENV' => 'testing']);
                $process = proc_open([PHP_BINARY, $probe, '1', '1', $ready, $release, 'barrier_probe'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, base_path(), $environment);
                $this->assertIsResource($process);
                fclose($pipes[0]);
                stream_set_blocking($pipes[1], false);
                stream_set_blocking($pipes[2], false);
                $workers[] = ['process' => $process, 'stdout' => $pipes[1], 'stderr' => $pipes[2]];
            }

            $readyDeadline = microtime(true) + 5;
            while (microtime(true) < $readyDeadline) {
                clearstatcache(true, $firstReady);
                clearstatcache(true, $secondReady);
                if (is_file($firstReady) && is_file($secondReady)) {
                    break;
                }
                usleep(25_000);
            }
            clearstatcache(true, $firstReady);
            clearstatcache(true, $secondReady);
            $this->assertTrue(is_file($firstReady));
            $this->assertTrue(is_file($secondReady));
            $this->assertSame(7, file_put_contents($release, 'release', LOCK_EX));
            clearstatcache(true, $release);
            $this->assertTrue(is_file($release));
            $this->assertSame('release', file_get_contents($release));

            foreach ($workers as $worker) {
                $deadline = microtime(true) + 5;
                $workerExitCode = null;
                while (microtime(true) < $deadline) {
                    $status = proc_get_status($worker['process']);
                    if (! $status['running']) {
                        $workerExitCode = $status['exitcode'] >= 0 ? $status['exitcode'] : null;
                        break;
                    }
                    usleep(25_000);
                }
                $stdout = stream_get_contents($worker['stdout']) ?: '';
                $stderr = stream_get_contents($worker['stderr']) ?: '';
                fclose($worker['stdout']);
                fclose($worker['stderr']);
                $exitCode = $workerExitCode ?? proc_close($worker['process']);
                if ($workerExitCode !== null) {
                    proc_close($worker['process']);
                }
                $this->assertSame(0, $exitCode, $stderr);
                $this->assertSame(['result' => 'success', 'ok' => true], json_decode($stdout, true));
            }
            $workers = [];
        } finally {
            foreach ($workers as $worker) {
                @proc_terminate($worker['process']);
                @fclose($worker['stdout']);
                @fclose($worker['stderr']);
                @proc_close($worker['process']);
            }
            @unlink($firstReady);
            @unlink($secondReady);
            @unlink($release);
            @rmdir($directory);
        }

        $this->assertDirectoryDoesNotExist($directory);
    }
}
