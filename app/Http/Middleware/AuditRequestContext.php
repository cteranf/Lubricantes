<?php

namespace App\Http\Middleware;

use App\Services\AuditService;
use App\Support\AuditEventContract;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class AuditRequestContext
{
    public const REQUEST_ID_ATTRIBUTE = 'audit.request_id';

    public const CORRELATION_ID_ATTRIBUTE = 'audit.correlation_id';

    public function __construct(private AuditService $audit) {}

    public function handle(Request $request, Closure $next): Response
    {
        $requestIdWasPresent = $request->attributes->has(self::REQUEST_ID_ATTRIBUTE);
        $previousRequestId = $request->attributes->get(self::REQUEST_ID_ATTRIBUTE);
        $correlationWasPresent = $request->attributes->has(self::CORRELATION_ID_ATTRIBUTE);
        $previousCorrelation = $request->attributes->get(self::CORRELATION_ID_ATTRIBUTE);
        $denialWasPresent = $request->attributes->has('audit.denial_recorded');
        $previousDenial = $request->attributes->get('audit.denial_recorded');
        $requestId = (string) Str::uuid();
        $correlation = $request->header('X-Correlation-ID');
        $request->attributes->set(self::REQUEST_ID_ATTRIBUTE, $requestId);
        $request->attributes->set(self::CORRELATION_ID_ATTRIBUTE, is_string($correlation) && self::isUuid($correlation) ? strtolower($correlation) : null);
        $request->attributes->set('audit.denial_recorded', false);

        try {
            try {
                $response = $next($request);
            } catch (AuthorizationException|ModelNotFoundException|HttpExceptionInterface $exception) {
                $status = $exception instanceof AuthorizationException ? 403 : ($exception instanceof ModelNotFoundException ? 404 : $exception->getStatusCode());
                $this->recordProtectedDenial($request, $status);

                throw $exception;
            }
            if (in_array($response->getStatusCode(), [403, 404], true)) {
                $this->recordProtectedDenial($request, $response->getStatusCode());
            }
            $response->headers->set('X-Request-ID', $requestId);

            return $response;
        } finally {
            self::restoreAttribute($request, self::REQUEST_ID_ATTRIBUTE, $requestIdWasPresent, $previousRequestId);
            self::restoreAttribute($request, self::CORRELATION_ID_ATTRIBUTE, $correlationWasPresent, $previousCorrelation);
            self::restoreAttribute($request, 'audit.denial_recorded', $denialWasPresent, $previousDenial);
        }
    }

    private static function restoreAttribute(Request $request, string $key, bool $wasPresent, mixed $value): void
    {
        if ($wasPresent) {
            $request->attributes->set($key, $value);

            return;
        }

        $request->attributes->remove($key);
    }

    public static function isUuid(string $value): bool
    {
        return preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/iD', $value) === 1;
    }

    private function recordProtectedDenial(Request $request, int $status): void
    {
        if (! in_array($status, [403, 404], true) || $request->attributes->get('audit.denial_recorded') === true) {
            return;
        }
        $route = $request->route();
        $middleware = $route ? $route->gatherMiddleware() : [];
        $protected = collect($middleware)->contains(static fn (string $entry): bool => str_starts_with($entry, 'auth:') || in_array($entry, ['active_user', 'is_admin', 'is_treasury'], true)
        );
        if (! $protected) {
            return;
        }
        $request->attributes->set('audit.denial_recorded', true);
        $this->audit->recordFailureOpen(
            AuditEventContract::SECURITY_ACCESS_DENIED,
            $request->user(),
            AuditEventContract::RESULT_DENIED,
            AuditEventContract::FAILURE_PROTECTED_ACCESS_DENIED,
            request: $request
        );
    }
}
