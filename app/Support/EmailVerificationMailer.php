<?php

namespace App\Support;

use InvalidArgumentException;

final class EmailVerificationMailer
{
    private const DIRECT_TRANSPORTS = ['smtp', 'sendmail'];

    public static function configuredName(): string
    {
        $name = config('email_verification.mailer');
        $mailers = config('mail.mailers', []);

        if (! is_string($name) || $name === '' || ! is_array($mailers)) {
            throw new InvalidArgumentException('Email verification mailer configuration is invalid.');
        }

        self::assertSafeMailer($name, $mailers, []);

        return $name;
    }

    /** @param array<string, mixed> $mailers @param array<string, true> $visited */
    private static function assertSafeMailer(string $name, array $mailers, array $visited): void
    {
        if (isset($visited[$name]) || ! isset($mailers[$name]) || ! is_array($mailers[$name])) {
            throw new InvalidArgumentException('Email verification mailer configuration is invalid.');
        }

        $visited[$name] = true;
        $mailer = $mailers[$name];
        $transport = $mailer['transport'] ?? $mailer['driver'] ?? null;

        if (! is_string($transport) || $transport === 'log') {
            throw new InvalidArgumentException('Email verification mailer configuration is invalid.');
        }

        if ($transport === 'failover') {
            $references = $mailer['mailers'] ?? null;
            if (! is_array($references) || $references === []) {
                throw new InvalidArgumentException('Email verification mailer configuration is invalid.');
            }

            foreach ($references as $reference) {
                if (! is_string($reference) || $reference === '') {
                    throw new InvalidArgumentException('Email verification mailer configuration is invalid.');
                }
                self::assertSafeMailer($reference, $mailers, $visited);
            }

            return;
        }

        if (! in_array($transport, self::DIRECT_TRANSPORTS, true) || isset($mailer['mailers'])) {
            throw new InvalidArgumentException('Email verification mailer configuration is invalid.');
        }
    }
}
