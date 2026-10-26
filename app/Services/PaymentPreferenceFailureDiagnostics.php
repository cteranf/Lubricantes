<?php

namespace App\Services;

use App\Models\PaymentPreference;
use MercadoPago\Exceptions\MPApiException;
use Throwable;

final class PaymentPreferenceFailureDiagnostics
{
    private const PROVIDER_ERRORS = [
        'invalid_access_token', 'unauthorized', 'forbidden', 'bad_request',
        'invalid_items', 'invalid_payer', 'invalid_back_urls', 'invalid_auto_return',
        'invalid_notification_url', 'invalid_currency_id', 'invalid_unit_price',
        'invalid_quantity', 'invalid_request', 'internal_error', 'too_many_requests',
    ];

    public static function context(PaymentPreference $preference, Throwable $exception, string $stage): array
    {
        $status = null;
        $body = [];
        // Wrapped SDK errors may still carry the provider response. Never serialize them.
        $cause = $exception;
        for ($depth = 0; $cause && $depth < 8; $depth++, $cause = $cause->getPrevious()) {
            if ($cause instanceof MPApiException) {
                $status = $cause->getStatusCode();
                try {
                    $body = $cause->getApiResponse()->getContent();
                } catch (Throwable) {
                    // This SDK returns ?array through an array return type for empty bodies.
                }
                break;
            }
        }
        $code = $body['error'] ?? $body['code'] ?? null;
        $providerCode = is_string($code) && in_array($code, self::PROVIDER_ERRORS, true) ? $code : null;
        $requestId = null;
        // MPResponse has no header API. Only accept a UUID from explicit response ID fields.
        foreach (['request_id', 'correlation_id'] as $field) {
            $value = $body[$field] ?? null;
            if (is_string($value) && preg_match('/\A[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}\z/i', $value) === 1) {
                $requestId = $value;
                break;
            }
        }

        return [
            'exception_class' => preg_match('/\A[A-Za-z_][A-Za-z0-9_\\\\]*\z/', get_class($exception)) === 1 ? get_class($exception) : 'anonymous_exception',
            'exception_code' => is_int($exception->getCode()) ? $exception->getCode() : null,
            'http_status' => $status !== null && $status >= 100 && $status <= 599 ? $status : null,
            'provider_error_code' => $providerCode,
            'message' => self::message($exception, $status),
            'provider_request_id' => $requestId,
            'order_id' => (int) $preference->order_id,
            'payment_preference_id' => (int) $preference->id,
            'gateway' => in_array($preference->gateway, ['mercadopago', 'mock'], true) ? $preference->gateway : 'unknown',
            'stage' => in_array($stage, ['gateway_initialization', 'remote_creation'], true) ? $stage : 'unknown',
            ...self::origin($exception),
            'error_category' => self::errorCategory($exception),
        ];
    }

    private static function origin(Throwable $exception): array
    {
        $origin = ['origin_component' => 'unknown', 'origin_file' => 'unknown'];
        try {
            $origin = self::resolveOriginFile($exception->getFile(), [
                ['path' => base_path('app'), 'component' => 'app', 'prefix' => 'app/'],
                ['path' => \Composer\InstalledVersions::getInstallPath('mercadopago/dx-php'), 'component' => 'mercadopago_sdk', 'prefix' => 'vendor/mercadopago/dx-php/'],
            ], static fn (string $operation, string $path): mixed => match ($operation) {
                'realpath' => @realpath($path),
                'is_link' => @is_link($path),
                'is_file' => @is_file($path),
            });
        } catch (Throwable) {
            // Filesystem/installation uncertainty must not suppress the other safe evidence.
        }
        $line = $exception->getLine();

        return [
            ...$origin,
            'origin_line' => $line > 0 && $line <= 1000000 ? $line : null,
        ];
    }

    /** Filesystem probes are injected only into this private policy for portable tests. */
    private static function resolveOriginFile(string $path, array $roots, \Closure $probe): array
    {
        $unknown = ['origin_component' => 'unknown', 'origin_file' => 'unknown'];
        // Reject ambiguous input before replacing any separators.
        if (str_contains($path, '\\') && str_contains($path, '/')) {
            return $unknown;
        }
        $path = str_replace('\\', '/', $path);
        if (preg_match('/\A(?:[A-Za-z]:\/|\/)/', $path) !== 1
            || preg_match('/(?:\A|\/)\.{1,2}(?:\/|\z)/', $path) === 1
            || str_contains($path, '//') || str_contains($path, "\0")) {
            return $unknown;
        }
        try {
            foreach ($roots as $root) {
                if (! is_string($root['path'] ?? null)) {
                    continue;
                }
                $logicalRoot = rtrim(str_replace('\\', '/', $root['path']), '/');
                $canonicalRoot = $probe('realpath', $logicalRoot);
                if (! is_string($canonicalRoot) || $probe('is_link', $logicalRoot) !== false) {
                    continue;
                }
                $canonicalRoot = rtrim(str_replace('\\', '/', $canonicalRoot), '/');
                $windows = preg_match('/\A[A-Za-z]:\//', $logicalRoot) === 1;
                $equal = static fn (string $a, string $b): bool => $windows ? strcasecmp($a, $b) === 0 : $a === $b;
                $matchedRoot = null;
                foreach ([$logicalRoot, $canonicalRoot] as $candidateRoot) {
                    $boundary = $candidateRoot.'/';
                    if ($equal(substr($path, 0, strlen($boundary)), $boundary)) {
                        $matchedRoot = $candidateRoot;
                        break;
                    }
                }
                if ($matchedRoot === null) {
                    continue;
                }
                $boundary = $matchedRoot.'/';
                $relative = substr($path, strlen($boundary));
                $file = $root['prefix'].$relative;
                if (strlen($file) > 240 || preg_match('/\A(?:[A-Za-z_][A-Za-z0-9_-]{0,63}\/)*[A-Za-z_][A-Za-z0-9_]{0,63}\.php\z/', $relative) !== 1) {
                    return $unknown;
                }
                $cursor = $matchedRoot;
                $expected = $canonicalRoot;
                // Inspect every segment, including the file, even if a link stays inside the root.
                foreach (explode('/', $relative) as $segment) {
                    $cursor .= '/'.$segment;
                    $expected .= '/'.$segment;
                    $resolved = $probe('realpath', $cursor);
                    if ($probe('is_link', $cursor) !== false || ! is_string($resolved)
                        || ! $equal(str_replace('\\', '/', $resolved), $expected)) {
                        return $unknown;
                    }
                }
                if ($probe('is_file', $path) !== true) {
                    return $unknown;
                }

                return ['origin_component' => $root['component'], 'origin_file' => $file];
            }
        } catch (Throwable) {
            return $unknown;
        }

        return $unknown;
    }

    private static function errorCategory(Throwable $exception): string
    {
        if ($exception instanceof \ErrorException && in_array($exception->getSeverity(), [E_DEPRECATED, E_USER_DEPRECATED], true)) {
            return 'deprecated_runtime';
        }
        if ($exception instanceof \TypeError || $exception instanceof \InvalidArgumentException) {
            return 'invalid_argument_shape';
        }
        foreach ([
            '/\AUndefined array key\b/i' => 'undefined_array_key',
            '/\AUndefined property\b/i' => 'undefined_property',
            '/\A(?:Deprecated:|Creation of dynamic property\b.*\bis deprecated\b)/i' => 'deprecated_runtime',
            '/\A(?:Trying to access array offset on value of type\b|foreach\(\) argument must be\b|Attempt to read property\b)/i' => 'invalid_argument_shape',
            '/(?:Permission denied|Read-only file system|failed to open stream|No such file or directory)/i' => 'filesystem_or_permission',
            '/(?:Could not resolve host|Failed to connect|Operation timed out|Connection timed out|SSL certificate problem|cURL error\b)/i' => 'network_runtime',
        ] as $pattern => $category) {
            if (preg_match($pattern, $exception->getMessage()) === 1) {
                return $category;
            }
        }

        return 'unknown_runtime_error';
    }

    private static function message(Throwable $exception, ?int $status): string
    {
        if ($status !== null) {
            return 'Provider returned an HTTP error; response text withheld.';
        }
        if ($exception instanceof \TypeError) {
            return 'Gateway or SDK type error; exception text withheld.';
        }
        // Categorize known transport failures without copying any variable request text.
        foreach ([
            'SSL certificate problem' => 'TLS certificate validation failed.',
            'Could not resolve host' => 'Provider hostname resolution failed.',
            'Operation timed out' => 'Provider request timed out; remote result uncertain.',
            'Connection timed out' => 'Provider connection timed out; remote result uncertain.',
            'Failed to connect' => 'Provider connection failed; remote result uncertain.',
        ] as $pattern => $message) {
            if (str_contains($exception->getMessage(), $pattern)) {
                return $message;
            }
        }

        return 'Unclassified gateway failure; exception and request text withheld.';
    }
}
