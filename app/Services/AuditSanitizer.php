<?php

namespace App\Services;

use App\Support\AuditEventContract;
use InvalidArgumentException;

class AuditSanitizer
{
    private const FORBIDDEN_KEY_PATTERN = '/password|token|secret|authorization|cookie|payload|card|credential|signature|receipt|file|checkout|account_number|cci/i';

    public function snapshot(string $action, ?array $values): ?array
    {
        if ($values === null) {
            return null;
        }

        $allowed = AuditEventContract::allowedSnapshotKeys($action);
        if (array_diff(array_keys($values), $allowed) !== []) {
            throw new InvalidArgumentException('La auditoría contiene campos de snapshot no permitidos.');
        }

        $safe = [];
        foreach ($values as $key => $value) {
            if (preg_match(self::FORBIDDEN_KEY_PATTERN, (string) $key) === 1) {
                throw new InvalidArgumentException('La auditoría contiene un campo prohibido.');
            }

            if ($key === 'role') {
                if (! is_string($value) || ! in_array($value, ['admin', 'customer', 'treasury'], true)) {
                    throw new InvalidArgumentException('El valor de auditoría no es válido.');
                }
            } elseif ($key === 'is_active') {
                if (! is_bool($value)) {
                    throw new InvalidArgumentException('El valor de auditoría no es válido.');
                }
            } else {
                throw new InvalidArgumentException('El campo de auditoría no es válido.');
            }
            $safe[$key] = $value;
        }

        $this->assertSize($safe, (int) config('audit.snapshot_max_bytes', 1024));

        return $safe;
    }

    public function metadata(string $action, ?array $metadata): ?array
    {
        if ($metadata === null) {
            return null;
        }

        $allowed = AuditEventContract::allowedMetadataKeys($action);
        if (array_diff(array_keys($metadata), $allowed) !== []) {
            throw new InvalidArgumentException('La auditoría contiene metadata no permitida.');
        }

        $safe = [];
        foreach ($metadata as $key => $value) {
            if (preg_match(self::FORBIDDEN_KEY_PATTERN, (string) $key) === 1) {
                throw new InvalidArgumentException('La auditoría contiene metadata prohibida.');
            }

            if ($key === 'changed_fields') {
                if (! is_string($value) || strlen($value) > 160 || preg_match('/\A(?:name|email|phone|can_deliver|show_reviews_publicly|accept_new_reviews|require_moderation)(?:,(?:name|email|phone|can_deliver|show_reviews_publicly|accept_new_reviews|require_moderation))*\z/D', $value) !== 1) {
                    throw new InvalidArgumentException('La lista de campos de auditoría no es válida.');
                }
            } elseif ($key === 'reason') {
                if (! is_string($value) || ! in_array($value, ['logout', 'user_deactivated', 'password_changed', 'password_reset'], true)) {
                    throw new InvalidArgumentException('La razón de auditoría no es válida.');
                }
            } elseif ($key === 'revoked_count') {
                if (! is_int($value) || $value < 0) {
                    throw new InvalidArgumentException('El contador de auditoría no es válido.');
                }
            } elseif ($key === 'http_status') {
                if (! is_int($value) || ! in_array($value, [403, 404], true)) {
                    throw new InvalidArgumentException('El estado de auditoría no es válido.');
                }
            } elseif ($key === 'command_name') {
                if (! is_string($value) || preg_match('/\A[a-z0-9:.\-]{1,80}\z/D', $value) !== 1) {
                    throw new InvalidArgumentException('El nombre de comando no es válido.');
                }
            } elseif ($key === 'scope') {
                if (! is_string($value) || ! in_array($value, ['list', 'detail'], true)) {
                    throw new InvalidArgumentException('El alcance de auditoría no es válido.');
                }
            } elseif ($key === 'page') {
                if (! is_int($value) || $value < 1) {
                    throw new InvalidArgumentException('La página de auditoría no es válida.');
                }
            } elseif ($key === 'per_page') {
                if (! is_int($value) || $value < 1 || $value > 100) {
                    throw new InvalidArgumentException('El tamaño de página de auditoría no es válido.');
                }
            } elseif ($key === 'audit_log_id') {
                if (! is_string($value) || preg_match('/\A[1-9][0-9]{0,19}\z/D', $value) !== 1) {
                    throw new InvalidArgumentException('La referencia de auditoría no es válida.');
                }
            } elseif ($key === 'review_id') {
                if (! is_string($value) || preg_match('/\A[1-9][0-9]{0,19}\z/D', $value) !== 1) {
                    throw new InvalidArgumentException('La referencia de auditoría no es válida.');
                }
            } elseif (in_array($key, ['from_status', 'to_status'], true)) {
                if (! is_string($value) || ! in_array($value, ['pending', 'published', 'hidden', 'rejected'], true)) {
                    throw new InvalidArgumentException('El estado de auditoría no es válido.');
                }
            } else {
                throw new InvalidArgumentException('La clave de metadata no es válida.');
            }
            $safe[$key] = $value;
        }

        $this->assertSize($safe, (int) config('audit.metadata_max_bytes', 1024));

        return $safe;
    }

    public function userAgent(?string $value): ?string
    {
        return null;
    }

    public function anonymizeIp(?string $ip): ?string
    {
        if ($ip === null || ($packed = @inet_pton($ip)) === false) {
            return null;
        }

        if (strlen($packed) === 4) {
            $packed[3] = "\0";
        } else {
            $packed = substr($packed, 0, 8).str_repeat("\0", 8);
        }

        $anonymous = inet_ntop($packed);

        return is_string($anonymous) ? $anonymous : null;
    }

    public function safeIdentifier(?string $value, int $maxLength): ?string
    {
        if ($value === null) {
            return null;
        }
        if ($value === '' || strlen($value) > $maxLength || preg_match('/\A[a-zA-Z0-9._:\-]+\z/D', $value) !== 1) {
            throw new InvalidArgumentException('La referencia de auditoría no es válida.');
        }

        return $value;
    }

    private function assertSize(array $value, int $maxBytes): void
    {
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (! is_string($encoded) || strlen($encoded) > $maxBytes) {
            throw new InvalidArgumentException('La auditoría excede el tamaño permitido.');
        }
    }
}
