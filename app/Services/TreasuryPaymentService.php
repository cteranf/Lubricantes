<?php

namespace App\Services;

use App\Models\Order;
use App\Models\PaymentSubmission;
use Illuminate\Validation\ValidationException;

class TreasuryPaymentService
{
    public function normalizeChannel(string $channel): string
    {
        $channel = strtolower(trim($channel));
        if (! in_array($channel, PaymentSubmission::CHANNELS, true)) {
            $this->invalid('El canal de pago no es válido.');
        }

        return $channel;
    }

    public function normalizeOperationNumber(mixed $number): string
    {
        if (! is_string($number)) {
            $this->invalid('El número de operación debe enviarse como texto.');
        }

        $normalized = preg_replace('/[^A-Z0-9]/', '', strtoupper(trim($number)));
        if ($normalized === '' || strlen($normalized) > config('treasury.operation_number_max_length')) {
            $this->invalid('El número de operación no es válido.');
        }

        return $normalized;
    }

    public function validateSubmission(string $channel, ?string $last4, ?string $bank): array
    {
        $channel = $this->normalizeChannel($channel);
        $last4 = $last4 === null ? null : trim($last4);
        $bank = $bank === null ? null : trim($bank);
        if (in_array($channel, ['yape', 'plin'], true) && ! preg_match('/^\d{4}$/', (string) $last4)) {
            $this->invalid('Yape y Plin requieren los últimos cuatro dígitos del celular de origen.');
        } if ($channel === 'bank_transfer' && $bank !== null && strlen($bank) > config('treasury.origin_bank_max_length')) {
            $this->invalid('El banco de origen supera la longitud permitida.');
        }

        return compact('channel', 'last4', 'bank');
    }

    public function duplicateFingerprint(string $channel, string $receivingAccountIdentifier, string $operationNumber): string
    {
        return hash('sha256', implode('|', [$this->normalizeChannel($channel), $this->normalizeReceivingAccountIdentifier($receivingAccountIdentifier), $this->normalizeOperationNumber($operationNumber)]));
    }

    public function assertExpectedAmount(Order $order, mixed $amount, string $currency = 'PEN'): void
    {
        if ($currency !== 'PEN' || $this->decimalToCents($order->total) !== $this->decimalToCents($amount)) {
            $this->invalid('El importe debe coincidir exactamente con el total del pedido.');
        }
    }

    public function canTransition(string $from, string $to): bool
    {
        return in_array($to, match ($from) {
            PaymentSubmission::PENDING_REVIEW => [PaymentSubmission::OBSERVED, PaymentSubmission::APPROVED, PaymentSubmission::REJECTED, PaymentSubmission::EXPIRED, PaymentSubmission::CANCELED],PaymentSubmission::OBSERVED => [PaymentSubmission::PENDING_REVIEW, PaymentSubmission::REJECTED, PaymentSubmission::EXPIRED, PaymentSubmission::CANCELED],default => []
        }, true);
    }

    public function safeHistoryMetadata(array $metadata): array
    {
        return array_intersect_key($metadata, array_flip(['channel', 'expected_amount', 'currency', 'review_expires_at']));
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['payment_submission' => [$message]]);
    }

    private function decimalToCents(mixed $amount): int
    {
        // Amounts cross the service boundary as decimal strings. Accepting a
        // float would turn binary rounding (for example 0.1 + 0.2) into a
        // potentially approved payment amount.
        if (! is_string($amount) || ! preg_match('/^(?:0|[1-9]\d*)(?:\.\d{1,2})?$/', $amount)) {
            $this->invalid('El importe debe ser un decimal positivo con hasta dos decimales.');
        }

        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');
        $fraction = str_pad($fraction, 2, '0');

        return ((int) $whole * 100) + (int) $fraction;
    }

    private function normalizeReceivingAccountIdentifier(string $identifier): string
    {
        $normalized = preg_replace('/[^A-Z0-9]/', '', strtoupper(trim($identifier)));

        if ($normalized === '') {
            $this->invalid('La cuenta receptora no es válida.');
        }

        return $normalized;
    }
}
