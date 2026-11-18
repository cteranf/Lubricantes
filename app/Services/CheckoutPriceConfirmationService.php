<?php

namespace App\Services;

use Illuminate\Support\Facades\Crypt;
use Throwable;

class CheckoutPriceConfirmationService
{
    public function issue(array $snapshot): string
    {
        return Crypt::encryptString(json_encode([
            'snapshot_hash' => $this->fingerprint($snapshot),
            'expires_at' => now()->addMinutes(5)->timestamp,
        ], JSON_THROW_ON_ERROR));
    }

    public function confirms(?string $token, array $snapshot, int $expectedTotalMinor): bool
    {
        if (! is_string($token) || $token === '') {
            return false;
        }

        try {
            $payload = json_decode(Crypt::decryptString($token), true, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return false;
        }

        return is_array($payload)
            && is_int($payload['expires_at'] ?? null)
            && $payload['expires_at'] >= now()->timestamp
            && is_string($payload['snapshot_hash'] ?? null)
            && hash_equals($payload['snapshot_hash'], $this->fingerprint($snapshot))
            && (int) ($snapshot['total_minor'] ?? -1) === $expectedTotalMinor;
    }

    private function fingerprint(array $snapshot): string
    {
        return hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR));
    }
}
