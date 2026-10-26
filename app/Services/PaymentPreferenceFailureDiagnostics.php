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
        ];
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
