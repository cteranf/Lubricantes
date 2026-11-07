<?php

namespace App\Support;

use App\Services\AuditSanitizer;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Immutable, sanitized payload for one central audit event.
 *
 * Inputs are reduced to allowlisted scalar data at construction. The original
 * arrays, request, and model objects are never retained.
 */
final class AuditEventData
{
    /** @var array<string, mixed> */
    private array $values;

    /** @param array<string, mixed> $values */
    private function __construct(array $values)
    {
        $this->values = $values;
    }

    /**
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     * @param  array<string, mixed>|null  $metadata
     */
    public static function fromInput(
        AuditSanitizer $sanitizer,
        string $actorType,
        ?int $actorId,
        string $action,
        string $category,
        string $result,
        ?string $failureCode,
        ?string $auditableType,
        ?string $auditableId,
        ?array $oldValues,
        ?array $newValues,
        ?array $metadata,
        ?string $specializedLogType,
        ?string $specializedLogId,
        string $requestId,
        ?string $correlationId,
        ?string $routeName,
        ?string $httpMethod,
        ?string $ipAddress,
        ?string $userAgent,
        string $retentionCategory
    ): self {
        $now = now('UTC');
        $values = [
            'occurred_at' => $now->format('Y-m-d H:i:s'),
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'action' => $action,
            'category' => $category,
            'auditable_type' => $auditableType,
            'auditable_id' => $sanitizer->safeIdentifier($auditableId, 120),
            'result' => $result,
            'failure_code' => $failureCode,
            'request_id' => $requestId,
            'correlation_id' => $correlationId,
            'route_name' => $routeName,
            'http_method' => $httpMethod,
            'ip_address' => $sanitizer->anonymizeIp($ipAddress),
            'user_agent' => $sanitizer->userAgent($userAgent),
            'old_values' => $sanitizer->snapshot($action, $oldValues),
            'new_values' => $sanitizer->snapshot($action, $newValues),
            'metadata' => $sanitizer->metadata($action, $metadata),
            'specialized_log_type' => $specializedLogType,
            'specialized_log_id' => $sanitizer->safeIdentifier($specializedLogId, 120),
            'retention_category' => $retentionCategory,
            'created_at' => now('UTC')->format('Y-m-d H:i:s'),
        ];

        self::assertValidValues($values, $sanitizer);

        return new self($values);
    }

    /** Recheck the persisted representation immediately before insertion. */
    public function assertValid(): void
    {
        self::assertValidValues($this->values, new AuditSanitizer);
    }

    /** @return array<string, mixed> */
    public function toPersistenceArray(): array
    {
        return $this->values;
    }

    /** @param array<string, mixed> $values */
    private static function assertValidValues(array $values, AuditSanitizer $sanitizer): void
    {
        $expectedKeys = [
            'occurred_at', 'actor_type', 'actor_id', 'action', 'category', 'auditable_type', 'auditable_id',
            'result', 'failure_code', 'request_id', 'correlation_id', 'route_name', 'http_method', 'ip_address',
            'user_agent', 'old_values', 'new_values', 'metadata', 'specialized_log_type', 'specialized_log_id',
            'retention_category', 'created_at',
        ];
        if (array_keys($values) !== $expectedKeys) {
            throw new InvalidArgumentException('La representación del evento de auditoría no es válida.');
        }

        $action = $values['action'];
        if (! is_string($action) || ! in_array($action, AuditEventContract::ACTIONS, true)
            || ! is_string($values['actor_type']) || ! in_array($values['actor_type'], AuditEventContract::ACTOR_TYPES, true)
            || ! is_string($values['result']) || ! in_array($values['result'], AuditEventContract::RESULTS, true)
            || $values['result'] !== AuditEventContract::expectedResult($action)
            || $values['category'] !== AuditEventContract::categoryFor($action)
            || $values['retention_category'] !== AuditEventContract::retentionFor($action)
            || $values['failure_code'] !== AuditEventContract::expectedFailureCode($action)) {
            throw new InvalidArgumentException('La acción y los valores del evento de auditoría no son coherentes.');
        }

        $actorId = $values['actor_id'];
        if (($actorId !== null && (! is_int($actorId) || $actorId < 1))
            || ($values['actor_type'] !== AuditEventContract::ACTOR_USER && $actorId !== null)) {
            throw new InvalidArgumentException('El actor del evento de auditoría no es válido.');
        }

        $auditableType = $values['auditable_type'];
        $specializedType = $values['specialized_log_type'];
        if (($auditableType === null) !== ($values['auditable_id'] === null)
            || ($auditableType !== null && (! is_string($auditableType) || ! in_array($auditableType, AuditEventContract::AUDITABLE_TYPES, true)))
            || (($specializedType === null) !== ($values['specialized_log_id'] === null))
            || ($specializedType !== null && (! is_string($specializedType) || ! in_array($specializedType, AuditEventContract::SPECIALIZED_LOG_TYPES, true)))
            || ($values['failure_code'] !== null && (! is_string($values['failure_code']) || ! in_array($values['failure_code'], AuditEventContract::FAILURE_CODES, true)))) {
            throw new InvalidArgumentException('Las referencias del evento de auditoría no son válidas.');
        }

        self::assertNullableString($values['auditable_id'], 120, '/\A[a-zA-Z0-9._:\-]+\z/D');
        self::assertNullableString($values['specialized_log_id'], 120, '/\A[a-zA-Z0-9._:\-]+\z/D');
        self::assertNullableString($values['route_name'], 120, '/\A[a-zA-Z0-9._:\-]+\z/D');
        self::assertNullableString($values['http_method'], 10, '/\A[A-Z]{1,10}\z/D');
        self::assertNullableString($values['ip_address'], 45, null);
        self::assertNullableString($values['user_agent'], (int) config('audit.user_agent_max_length', 256), null);
        self::assertNullableString($values['failure_code'], 64, null);
        self::assertNullableString($values['correlation_id'], 36, null);
        if (! self::isUuid($values['request_id']) || ($values['correlation_id'] !== null && ! self::isUuid($values['correlation_id']))) {
            throw new InvalidArgumentException('El contexto del evento de auditoría no es válido.');
        }
        if ($values['ip_address'] !== $sanitizer->anonymizeIp($values['ip_address'])
            || $values['user_agent'] !== $sanitizer->userAgent($values['user_agent'])) {
            throw new InvalidArgumentException('Los datos de contexto del evento de auditoría no están normalizados.');
        }
        foreach (['occurred_at', 'created_at'] as $timestampKey) {
            self::assertTimestamp($values[$timestampKey]);
        }
        foreach (['old_values', 'new_values', 'metadata'] as $jsonKey) {
            if ($values[$jsonKey] !== null && ! is_array($values[$jsonKey])) {
                throw new InvalidArgumentException('El contenido estructurado del evento de auditoría no es válido.');
            }
        }
        if ($values['old_values'] !== $sanitizer->snapshot($action, $values['old_values'])
            || $values['new_values'] !== $sanitizer->snapshot($action, $values['new_values'])
            || $values['metadata'] !== $sanitizer->metadata($action, $values['metadata'])) {
            throw new InvalidArgumentException('El contenido del evento de auditoría no está sanitizado.');
        }
    }

    private static function assertNullableString(mixed $value, int $maxLength, ?string $pattern): void
    {
        if ($value === null) {
            return;
        }
        if (! is_string($value) || $value === '' || strlen($value) > $maxLength || ($pattern !== null && preg_match($pattern, $value) !== 1)) {
            throw new InvalidArgumentException('Un campo del evento de auditoría no es válido.');
        }
    }

    private static function assertTimestamp(mixed $value): void
    {
        if (! is_string($value)) {
            throw new InvalidArgumentException('La fecha del evento de auditoría no es válida.');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) || $date->format('Y-m-d H:i:s') !== $value) {
            throw new InvalidArgumentException('La fecha del evento de auditoría no es válida.');
        }
    }

    private static function isUuid(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/iD', $value) === 1;
    }
}
