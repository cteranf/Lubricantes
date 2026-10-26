<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class PaymentTransaction extends Model
{
    private bool $allowResolutionApprovalTransition = false;

    private bool $allowRemoteStatusTransition = false;

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const RECEIVED = 'received';

    public const FAILED = 'failed';

    public const CANCELED = 'canceled';

    public const PAYMENT = 'payment';

    public const REFUND = 'refund';

    /**
     * Canonical unique scope for one approved financial payment per order.
     *
     * The legacy COD prefix is retained deliberately for compatibility with
     * existing rows; its scope is all payment methods, not only COD.
     */
    public static function approvedScopeKeyForOrder(int $orderId): string
    {
        return 'cod-approved-order-'.$orderId;
    }

    protected $fillable = ['order_id', 'gateway', 'payment_method', 'transaction_type', 'status', 'amount', 'currency', 'idempotency_key', 'approved_scope_key', 'external_reference', 'provider_payment_id', 'payment_preference_id', 'provider_status', 'provider_status_detail', 'verified_at', 'manual_reference', 'collection_method', 'collected_by', 'collected_at', 'confirmed_by', 'confirmed_at', 'failed_at', 'failure_reason', 'metadata'];

    protected $hidden = ['idempotency_key', 'approved_scope_key'];

    protected $casts = ['amount' => 'decimal:2', 'verified_at' => 'datetime', 'collected_at' => 'datetime', 'confirmed_at' => 'datetime', 'failed_at' => 'datetime', 'metadata' => 'array'];

    protected static function booted(): void
    {
        static::updating(function (self $transaction): void {
            $allowed = $transaction->allowResolutionApprovalTransition
                && $transaction->getOriginal('status') === self::RECEIVED
                && $transaction->status === self::APPROVED
                && $transaction->getOriginal('approved_scope_key') === null
                && $transaction->approved_scope_key !== null
                && empty(array_diff(array_keys($transaction->getDirty()), ['status', 'approved_scope_key', 'updated_at']));

            $remoteAllowed = $transaction->allowRemoteStatusTransition
                && $transaction->getOriginal('gateway') === 'mercadopago'
                && in_array($transaction->getOriginal('status'), [self::PENDING, self::FAILED], true)
                && in_array($transaction->getOriginal('provider_status'), ['pending', 'in_process'], true)
                && empty(array_diff(array_keys($transaction->getDirty()), ['status', 'provider_status', 'provider_status_detail', 'verified_at', 'approved_scope_key', 'confirmed_at', 'failed_at', 'failure_reason', 'updated_at']));
            if (! $allowed && ! $remoteAllowed) {
                throw new LogicException('Una transaccion financiera confirmada es inmutable.');
            }
        });
        static::deleting(fn () => throw new LogicException('Una transaccion financiera no se puede eliminar.'));
    }

    /** Only the financially locked processor may finalize a verified pending payment. */
    public function applyVerifiedRemoteStatus(string $status, ?string $detail): void
    {
        if ($this->getConnection()->transactionLevel() < 1 || ! $this->exists
            || $this->gateway !== 'mercadopago' || $this->transaction_type !== self::PAYMENT || $this->payment_method !== 'card'
            || ! in_array($this->status, [self::PENDING, self::FAILED], true)
            || ! in_array($this->provider_status, ['pending', 'in_process'], true)
            || ! in_array($status, ['pending', 'in_process', 'approved', 'rejected'], true)
            || $this->approved_scope_key !== null) {
            throw new LogicException('La transaccion no permite una transicion remota pendiente.');
        }
        $this->allowRemoteStatusTransition = true;
        try {
            $this->forceFill([
                'status' => $status === 'approved' ? self::APPROVED : ($status === 'rejected' ? self::FAILED : self::PENDING),
                'provider_status' => $status,
                'provider_status_detail' => $detail,
                'verified_at' => now(),
                'approved_scope_key' => $status === 'approved' ? self::approvedScopeKeyForOrder($this->order_id) : null,
                'confirmed_at' => $status === 'approved' ? now() : null,
                'failed_at' => $status === 'rejected' ? now() : null,
                'failure_reason' => $status === 'rejected' ? $detail : null,
            ])->save();
        } finally {
            $this->allowRemoteStatusTransition = false;
        }
    }

    public function approveReceivedForResolution(string $approvedScopeKey): void
    {
        if (! $this->exists || $this->status !== self::RECEIVED || $this->approved_scope_key !== null || $approvedScopeKey === '') {
            throw new LogicException('La transaccion recibida no permite esta aprobacion interna.');
        }

        $this->allowResolutionApprovalTransition = true;
        try {
            $this->forceFill([
                'status' => self::APPROVED,
                'approved_scope_key' => $approvedScopeKey,
            ])->save();
        } finally {
            $this->allowResolutionApprovalTransition = false;
        }
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function collector()
    {
        return $this->belongsTo(User::class, 'collected_by');
    }

    public function confirmer()
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function preference()
    {
        return $this->belongsTo(PaymentPreference::class, 'payment_preference_id');
    }

    public function paymentSubmission()
    {
        return $this->belongsTo(PaymentSubmission::class);
    }

    public function receivedResolutionCase()
    {
        return $this->hasOne(PaymentResolutionCase::class, 'received_payment_transaction_id');
    }

    public function refundAsOriginalPayment()
    {
        return $this->hasOne(PaymentRefund::class, 'original_payment_transaction_id');
    }

    public function refundAsAccountingTransaction()
    {
        return $this->hasOne(PaymentRefund::class, 'refund_payment_transaction_id');
    }
}
