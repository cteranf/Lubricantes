<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

class PaymentSubmission extends Model
{
    public const CHANNELS = ['yape', 'plin', 'bank_transfer'];

    public const PENDING_REVIEW = 'pending_review';

    public const OBSERVED = 'observed';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const EXPIRED = 'expired';

    public const CANCELED = 'canceled';

    public const MANUAL_RESOLUTION_REQUIRED = 'manual_resolution_required';

    public const REFUND_PENDING = 'refund_pending';

    public const REFUNDED = 'refunded';

    public const STATUSES = [self::PENDING_REVIEW, self::OBSERVED, self::APPROVED, self::REJECTED, self::EXPIRED, self::CANCELED, self::MANUAL_RESOLUTION_REQUIRED, self::REFUND_PENDING, self::REFUNDED];

    protected $fillable = ['order_id', 'channel', 'status', 'expected_amount', 'currency', 'operation_number', 'normalized_operation_number', 'duplicate_fingerprint', 'declared_paid_at', 'origin_phone_last4', 'origin_bank', 'receiving_account_snapshot', 'submitted_by', 'submitted_at', 'reviewed_by', 'reviewed_at', 'decision_reason', 'review_expires_at', 'idempotency_key'];

    protected $hidden = ['operation_number', 'normalized_operation_number', 'duplicate_fingerprint', 'origin_phone_last4', 'origin_bank', 'receiving_account_snapshot', 'idempotency_key'];

    protected $casts = ['expected_amount' => 'decimal:2', 'declared_paid_at' => 'datetime', 'receiving_account_snapshot' => 'array', 'submitted_at' => 'datetime', 'reviewed_at' => 'datetime', 'review_expires_at' => 'datetime'];

    protected static function booted(): void
    {
        static::saving(function (self $submission): void {
            $submission->status ??= self::PENDING_REVIEW;
            $submission->currency ??= 'PEN';

            if (! in_array($submission->channel, self::CHANNELS, true)) {
                throw new InvalidArgumentException('El canal de pago no es válido.');
            }

            if (! in_array($submission->status, self::STATUSES, true)) {
                throw new InvalidArgumentException('El estado de Tesorería no es válido.');
            }

            if ($submission->currency !== 'PEN') {
                throw new InvalidArgumentException('La moneda de Tesorería debe ser PEN.');
            }
        });
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function submitter()
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function histories()
    {
        return $this->hasMany(PaymentSubmissionHistory::class)->orderBy('occurred_at')->orderBy('id');
    }

    public function paymentTransaction()
    {
        return $this->hasOne(PaymentTransaction::class);
    }

    public function resolutionCase()
    {
        return $this->hasOne(PaymentResolutionCase::class);
    }

    /**
     * Safe display form for customer and Treasury-list contexts.
     *
     * The persisted operation remains untouched; even short values retain at
     * least one hidden character in their display form.
     */
    public function maskedOperationNumber(): string
    {
        $operation = (string) $this->operation_number;
        $length = strlen($operation);

        if ($length <= 2) {
            return str_repeat('•', $length);
        }

        $visibleLength = $length >= 7 ? 4 : 2;

        return str_repeat('•', $length - $visibleLength).substr($operation, -$visibleLength);
    }
}
