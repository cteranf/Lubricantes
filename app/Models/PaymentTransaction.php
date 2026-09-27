<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class PaymentTransaction extends Model
{
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
        static::updating(fn () => throw new LogicException('Una transaccion financiera confirmada es inmutable.'));
        static::deleting(fn () => throw new LogicException('Una transaccion financiera no se puede eliminar.'));
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

    public function refund()
    {
        return $this->hasOne(PaymentRefund::class, 'refund_payment_transaction_id');
    }
}
