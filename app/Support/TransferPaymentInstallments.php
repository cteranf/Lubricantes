<?php

namespace App\Support;

use App\Models\Order;
use App\Models\PaymentSubmission;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/** Exact-cent accounting rules shared by customer reporting and Treasury approval. */
final class TransferPaymentInstallments
{
    public static function cents(mixed $amount): int
    {
        if (! is_string($amount) && ! is_int($amount)) {
            throw new \InvalidArgumentException('Amount must be a decimal string or integer.');
        }

        $value = (string) $amount;
        if (! preg_match('/^(?:0|[1-9]\d*)(?:\.\d{1,2})?$/', $value)) {
            throw new \InvalidArgumentException('Amount must have at most two decimal places.');
        }

        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        if (strlen($whole) > 10) {
            throw new \InvalidArgumentException('Amount exceeds the supported order total.');
        }

        return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
    }

    public static function decimal(int $cents): string
    {
        return intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function limits(int $totalCents): array
    {
        return match (true) {
            $totalCents <= 100000 => ['max_operations' => 3, 'minimum_cents' => 5000],
            $totalCents <= 500000 => ['max_operations' => 5, 'minimum_cents' => 10000],
            default => ['max_operations' => 10, 'minimum_cents' => 20000],
        };
    }

    public static function summary(Order $order, Collection $submissions, ?int $excludeSubmissionId = null): array
    {
        $total = self::cents((string) $order->total);
        $limits = self::limits($total);
        $approved = 0;
        $pending = 0;
        $observed = 0;
        $rejected = 0;
        $manualResolution = 0;
        $activeOperations = 0;

        foreach ($submissions as $submission) {
            if ($excludeSubmissionId !== null && (int) $submission->id === $excludeSubmissionId) {
                continue;
            }
            $amount = self::cents((string) ($submission->amount ?? $submission->expected_amount));
            if ($submission->status === PaymentSubmission::APPROVED) {
                $approved += $amount;
                $activeOperations++;
            } elseif ($submission->status === PaymentSubmission::PENDING_REVIEW) {
                $pending += $amount;
                $activeOperations++;
            } elseif ($submission->status === PaymentSubmission::OBSERVED) {
                $observed += $amount;
                $activeOperations++;
            } elseif ($submission->status === PaymentSubmission::MANUAL_RESOLUTION_REQUIRED) {
                $observed += $amount;
                $manualResolution += $amount;
                $activeOperations++;
            } elseif ($submission->status === PaymentSubmission::REJECTED) {
                // Rejected submissions do not reserve balance or a slot, but
                // the reported funds still need a human reconciliation path.
                $rejected += $amount;
            }
        }

        $remaining = max(0, $total - $approved - $pending - $observed);
        $slots = max(0, $limits['max_operations'] - $activeOperations);

        return [
            'order_total' => self::decimal($total),
            'approved_amount' => self::decimal($approved),
            'in_review_amount' => self::decimal($pending),
            'observed_amount' => self::decimal($observed),
            'reported_rejected_amount' => self::decimal($rejected),
            'reported_unresolved_amount' => self::decimal($rejected + $manualResolution),
            'requires_manual_reconciliation' => $rejected > 0 || $manualResolution > 0,
            'remaining_amount' => self::decimal($remaining),
            'max_operations' => $limits['max_operations'],
            'minimum_amount' => self::decimal($limits['minimum_cents']),
            'operations_used' => $activeOperations,
            'slots_left' => $slots,
            'required_next_amount' => $slots === 1 ? self::decimal($remaining) : null,
            'payment_complete_in_review' => $remaining === 0 && $approved < $total,
        ];
    }

    public static function assertCanAdd(Order $order, Collection $submissions, int $amountCents, ?int $excludeSubmissionId = null): void
    {
        $summary = self::summary($order, $submissions, $excludeSubmissionId);
        if ($summary['requires_manual_reconciliation']) {
            throw ValidationException::withMessages(['amount' => ['Hay un abono rechazado que requiere revisión de Tesorería. Solicita esa revisión antes de registrar otro pago.']]);
        }
        $remaining = self::cents($summary['remaining_amount']);
        $minimum = self::cents($summary['minimum_amount']);

        if ($amountCents <= 0 || $amountCents > $remaining || $summary['slots_left'] <= 0) {
            throw ValidationException::withMessages(['amount' => ['El importe supera el saldo pendiente o los cupos disponibles.']]);
        }
        if ($amountCents < $minimum && $amountCents !== $remaining) {
            throw ValidationException::withMessages(['amount' => ['El abono debe alcanzar el mínimo permitido, salvo que liquide el saldo pendiente.']]);
        }
        if ($summary['slots_left'] === 1 && $amountCents !== $remaining) {
            throw ValidationException::withMessages(['amount' => ['Este es el último cupo; registra exactamente S/ '.self::decimal($remaining).'.']]);
        }
    }
}
