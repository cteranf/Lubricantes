<?php

namespace App\Services;

use Illuminate\Support\Str;

class TerritoryNormalizer
{
    public function normalize(?string $value): string
    {
        return Str::upper(Str::ascii($this->cleanName($value)));
    }

    public function cleanName(?string $value): string
    {
        $value = preg_replace('/[\x{200B}-\x{200D}\x{2060}\x{FEFF}]/u', '', (string) $value);

        return trim(preg_replace('/\s+/u', ' ', $value));
    }

    public function normalizeCode(?string $value): string
    {
        $value = preg_replace('/[\x{200B}-\x{200D}\x{2060}\x{FEFF}]/u', '', (string) $value);

        return Str::upper(preg_replace('/\s+/u', '', trim($value)));
    }

    public function identityKey(string $department, string $province, string $district): string
    {
        $identity = implode('|', array_map(fn (string $value) => $this->normalize($value), [$department, $province, $district]));

        return hash('sha256', $identity);
    }

    public function ubigeoIdentityKey(?string $ubigeo): ?string
    {
        return trim((string) $ubigeo) === '' ? null : hash('sha256', 'UBIGEO:'.$this->normalize($ubigeo));
    }
}
