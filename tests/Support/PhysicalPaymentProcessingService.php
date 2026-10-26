<?php

namespace Tests\Support;

use App\Models\Order;
use App\Services\PaymentProcessingService;
use Illuminate\Support\Facades\DB;

final class PhysicalPaymentProcessingService extends PaymentProcessingService
{
    private int $entries = 0;

    protected function afterRemoteOrderLock(Order $order): void
    {
        $fixturePath = getenv('SYNTHETIC_MP_RACE_FIXTURE');
        if (! is_string($fixturePath) || $fixturePath === '' || ! is_file($fixturePath)) {
            return;
        }
        $fixture = json_decode((string) file_get_contents($fixturePath), true, 32, JSON_THROW_ON_ERROR);
        if ((int) ($fixture['order_id'] ?? 0) !== (int) $order->id) {
            return;
        }

        $role = getenv('SYNTHETIC_MP_RACE_ROLE') ?: 'unknown';
        $root = $fixture['marker_root'];
        $attempt = ++$this->entries;
        $marker = $root.DIRECTORY_SEPARATOR.'entered-'.$role.'-'.$attempt.'.json';
        $handle = @fopen($marker, 'x');
        if ($handle === false) {
            throw new \RuntimeException('Unable to create an owned race marker.');
        }
        fwrite($handle, json_encode(['connection_id' => (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id, 'attempt' => $attempt]));
        fclose($handle);

        $deadline = microtime(true) + 30;
        while (! is_file($root.DIRECTORY_SEPARATOR.'release')) {
            if (microtime(true) >= $deadline) {
                throw new \RuntimeException('Physical race coordination expired.');
            }
            usleep(20000);
        }
    }
}
