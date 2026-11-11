<?php

namespace App\Support;

final class EmailVerificationSettings
{
    public const MIN_EXPIRATION_MINUTES = 1;

    public const MAX_EXPIRATION_MINUTES = 1440;

    public const DEFAULT_EXPIRATION_MINUTES = 60;

    public static function expiration(mixed $value): int
    {
        $minutes = filter_var($value, FILTER_VALIDATE_INT);

        if ($minutes === false) {
            $minutes = self::DEFAULT_EXPIRATION_MINUTES;
        }

        return min(self::MAX_EXPIRATION_MINUTES, max(self::MIN_EXPIRATION_MINUTES, $minutes));
    }
}
