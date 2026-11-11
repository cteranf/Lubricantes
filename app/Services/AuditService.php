<?php

namespace App\Services;

use App\Http\Middleware\AuditRequestContext;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\AuditEventContract;
use App\Support\AuditEventData;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PDOException;

class AuditService
{
    public function __construct(private AuditSanitizer $sanitizer) {}

    public function recordUser(
        string $action,
        ?User $actor,
        string $result,
        ?string $failureCode = null,
        ?string $auditableType = null,
        ?string $auditableId = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?array $metadata = null,
        ?string $specializedLogType = null,
        ?string $specializedLogId = null,
        ?Request $request = null
    ): AuditLog {
        return $this->write(AuditEventContract::ACTOR_USER, $actor?->id, $action, $result, $failureCode, $auditableType, $auditableId, $oldValues, $newValues, $metadata, $specializedLogType, $specializedLogId, $request);
    }

    public function recordSystem(
        string $action,
        string $result,
        ?string $failureCode = null,
        ?string $auditableType = null,
        ?string $auditableId = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?array $metadata = null,
        ?Request $request = null
    ): AuditLog {
        return $this->write(AuditEventContract::ACTOR_SYSTEM, null, $action, $result, $failureCode, $auditableType, $auditableId, $oldValues, $newValues, $metadata, null, null, $request);
    }

    public function recordCommand(
        string $action,
        string $result,
        ?string $failureCode = null,
        ?array $metadata = null,
        ?Request $request = null
    ): AuditLog {
        return $this->write(AuditEventContract::ACTOR_COMMAND, null, $action, $result, $failureCode, null, null, null, null, $metadata, null, null, $request);
    }

    /**
     * Best-effort is deliberately limited to unauthenticated failures and protected-access
     * denials. Contract validation happens before the persistence exception boundary.
     */
    public function recordFailureOpen(
        string $action,
        ?User $actor,
        string $result,
        string $failureCode,
        ?string $auditableType = null,
        ?string $auditableId = null,
        ?array $metadata = null,
        ?Request $request = null
    ): void {
        $allowed = [AuditEventContract::AUTH_LOGIN_FAILED, AuditEventContract::AUTH_LOGIN_DENIED, AuditEventContract::AUTH_LOGIN_UNVERIFIED_REJECTED, AuditEventContract::SECURITY_ACCESS_DENIED];
        if (! in_array($action, $allowed, true)) {
            throw new InvalidArgumentException('La acción no admite registro fail-open.');
        }

        $event = $this->eventData(AuditEventContract::ACTOR_USER, $actor?->id, $action, $result, $failureCode, $auditableType, $auditableId, null, null, $metadata, null, null, $request);
        try {
            $this->persist($event);
        } catch (QueryException|PDOException) {
            try {
                Log::warning('Audit event persistence failed for a fail-open security event.');
            } catch (\Throwable) {
                // The request outcome must remain independent of the operational logger.
            }
        }
    }

    private function write(
        string $actorType,
        ?int $actorId,
        string $action,
        string $result,
        ?string $failureCode,
        ?string $auditableType,
        ?string $auditableId,
        ?array $oldValues,
        ?array $newValues,
        ?array $metadata,
        ?string $specializedLogType,
        ?string $specializedLogId,
        ?Request $request
    ): AuditLog {
        return $this->persist($this->eventData($actorType, $actorId, $action, $result, $failureCode, $auditableType, $auditableId, $oldValues, $newValues, $metadata, $specializedLogType, $specializedLogId, $request));
    }

    private function eventData(
        string $actorType,
        ?int $actorId,
        string $action,
        string $result,
        ?string $failureCode,
        ?string $auditableType,
        ?string $auditableId,
        ?array $oldValues,
        ?array $newValues,
        ?array $metadata,
        ?string $specializedLogType,
        ?string $specializedLogId,
        ?Request $request
    ): AuditEventData {
        if (! in_array($actorType, AuditEventContract::ACTOR_TYPES, true)
            || ! in_array($action, AuditEventContract::ACTIONS, true)
            || ! in_array($result, AuditEventContract::RESULTS, true)
            || ($failureCode !== null && ! in_array($failureCode, AuditEventContract::FAILURE_CODES, true))) {
            throw new InvalidArgumentException('El evento de auditoría no cumple el contrato.');
        }
        if ($result !== AuditEventContract::expectedResult($action)
            || $failureCode !== AuditEventContract::expectedFailureCode($action)) {
            throw new InvalidArgumentException('La acción, el resultado y el código de auditoría no son coherentes.');
        }
        if (($auditableType === null) !== ($auditableId === null)
            || ($auditableType !== null && ! in_array($auditableType, AuditEventContract::AUDITABLE_TYPES, true))
            || (($specializedLogType === null) !== ($specializedLogId === null))
            || ($specializedLogType !== null && ! in_array($specializedLogType, AuditEventContract::SPECIALIZED_LOG_TYPES, true))) {
            throw new InvalidArgumentException('La entidad de auditoría no cumple el contrato.');
        }
        if ($actorType !== AuditEventContract::ACTOR_USER && $actorId !== null) {
            throw new InvalidArgumentException('El tipo de actor no admite identificador de usuario.');
        }

        $request ??= app()->bound('request') ? request() : null;
        $requestId = $request?->attributes->get(AuditRequestContext::REQUEST_ID_ATTRIBUTE);
        $correlationId = $request?->attributes->get(AuditRequestContext::CORRELATION_ID_ATTRIBUTE);
        if (! is_string($requestId) || ! AuditRequestContext::isUuid($requestId)) {
            $requestId = (string) Str::uuid();
        }
        if (! is_string($correlationId) || ! AuditRequestContext::isUuid($correlationId)) {
            $correlationId = null;
        }

        $routeName = $request?->route()?->getName();
        if (! is_string($routeName) || preg_match('/\A[a-zA-Z0-9._:\-]{1,120}\z/D', $routeName) !== 1) {
            $routeName = null;
        }

        return AuditEventData::fromInput(
            $this->sanitizer,
            $actorType,
            $actorId,
            $action,
            AuditEventContract::categoryFor($action),
            $result,
            $failureCode,
            $auditableType,
            $auditableId,
            $oldValues,
            $newValues,
            $metadata,
            $specializedLogType,
            $specializedLogId,
            $requestId,
            $correlationId,
            $routeName,
            $request ? strtoupper(substr($request->method(), 0, 10)) : null,
            $request?->ip(),
            $request?->userAgent(),
            AuditEventContract::retentionFor($action)
        );
    }

    protected function persist(AuditEventData $event): AuditLog
    {
        return AuditLog::appendEvent($event);
    }
}
