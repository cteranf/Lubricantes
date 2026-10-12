<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use LogicException;

class PaymentRefund extends Model
{
    public const PENDING = 'pending';

    public const COMPLETED = 'completed';

    public const FAILED = 'failed';

    public const STATUSES = [self::PENDING, self::COMPLETED, self::FAILED];

    protected $fillable = [
        'payment_resolution_case_id',
        'amount',
        'currency',
        'status',
        'reason',
        'requested_by',
        'completed_by',
        'requested_at',
        'completed_at',
        'failed_at',
    ];

    /**
     * Financial transaction identifiers are deliberately absent from fillable.
     * Trusted domain code must assign them explicitly when creating the refund;
     * the original payment identity must never change afterwards.
     */
    protected $hidden = [
        'reason',
        'external_reference',
        'idempotency_key',
        'originalPaymentTransaction',
        'original_payment_transaction',
        'refundPaymentTransaction',
        'refund_payment_transaction',
        'resolutionCase',
        'resolution_case',
    ];

    protected $casts = [
        'payment_resolution_case_id' => 'integer',
        'original_payment_transaction_id' => 'integer',
        'refund_payment_transaction_id' => 'integer',
        'requested_by' => 'integer',
        'completed_by' => 'integer',
        'amount' => 'decimal:2',
        'status' => 'string',
        'currency' => 'string',
        'external_reference' => 'encrypted',
        'requested_at' => 'datetime',
        'completed_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $refund): void {
            if ($refund->isDirty('original_payment_transaction_id')) {
                throw new LogicException('La transacción de pago original de una devolución es inmutable.');
            }
        });

        static::saving(function (self $refund): void {
            $refund->status ??= self::PENDING;
            $refund->currency ??= 'PEN';

            if (! in_array($refund->status, self::STATUSES, true) || $refund->currency !== 'PEN') {
                throw new InvalidArgumentException('El estado o moneda de devolución no es válido.');
            }

            $case = $refund->relationLoaded('resolutionCase')
                ? $refund->resolutionCase
                : PaymentResolutionCase::find($refund->payment_resolution_case_id);

            if ($case && ((string) $refund->amount !== (string) $case->amount || $refund->currency !== $case->currency)) {
                throw new InvalidArgumentException('La devolución debe conservar el importe y moneda íntegros del caso de resolución.');
            }

            $original = $refund->relationLoaded('originalPaymentTransaction')
                ? $refund->originalPaymentTransaction
                : PaymentTransaction::find($refund->original_payment_transaction_id);

            if ($original && (
                $original->transaction_type !== PaymentTransaction::PAYMENT
                || $original->status !== PaymentTransaction::APPROVED
                || (string) $refund->amount !== (string) $original->amount
                || $refund->currency !== $original->currency
            )) {
                throw new InvalidArgumentException('La devolución total debe corresponder a una transacción de pago aprobada por el mismo importe y moneda.');
            }

            if ($refund->original_payment_transaction_id !== null
                && $refund->refund_payment_transaction_id !== null
                && (int) $refund->original_payment_transaction_id === (int) $refund->refund_payment_transaction_id) {
                throw new InvalidArgumentException('La transacción de pago original y el asiento de devolución deben ser diferentes.');
            }
        });
    }

    public function originalPaymentTransaction()
    {
        return $this->belongsTo(PaymentTransaction::class, 'original_payment_transaction_id');
    }

    public function resolutionCase()
    {
        return $this->belongsTo(PaymentResolutionCase::class, 'payment_resolution_case_id');
    }

    public function refundPaymentTransaction()
    {
        return $this->belongsTo(PaymentTransaction::class, 'refund_payment_transaction_id');
    }

    public function requestedBy()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function completedBy()
    {
        return $this->belongsTo(User::class, 'completed_by');
    }
}
