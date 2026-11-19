<?php

namespace App\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

class CheckoutTaxQuoteService
{
    public function issue(array $snapshot): string
    {
        return Crypt::encryptString(json_encode([
            'hash' => $this->fingerprint($snapshot),
            'expires_at' => now()->addMinutes(20)->timestamp,
            'nonce' => (string) Str::uuid(),
        ], JSON_THROW_ON_ERROR));
    }

    public function confirms(?string $token, array $snapshot): bool
    {
        if (! $token) {
            return false;
        }

        try {
            $claim = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return false;
        }

        return is_array($claim)
            && (int) ($claim['expires_at'] ?? 0) >= now()->timestamp
            && is_string($claim['hash'] ?? null)
            && hash_equals($claim['hash'], $this->fingerprint($snapshot));
    }

    private function fingerprint(array $snapshot): string
    {
        return hash('sha256', json_encode($this->sortRecursively($snapshot), JSON_THROW_ON_ERROR));
    }

    private function sortRecursively(array $value): array
    {
        foreach ($value as &$item) {
            if (is_array($item)) {
                $item = $this->sortRecursively($item);
            }
        }
        unset($item);
        $isList = array_keys($value) === range(0, count($value) - 1);
        if ($value !== [] && ! $isList) {
            ksort($value);
        }

        return $value;
    }
}
