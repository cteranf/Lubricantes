<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

class PaymentResolutionCase extends Model
{
    public const STOCK_REASSIGNMENT = 'stock_reassignment';

    public const REFUND = 'refund';

    public const TYPES = [self::STOCK_REASSIGNMENT, self::REFUND];

    public const OPEN = 'open';

    public const REASSIGNED = 'reassigned';

    public const REFUND_PENDING = 'refund_pending';

    public const REFUNDED = 'refunded';

    public const REFUND_FAILED = 'refund_failed';

    public const STATUSES = [
        self::OPEN,
        self::REASSIGNED,
        self::REFUND_PENDING,
        self::REFUNDED,
        self::REFUND_FAILED,
    ];

    protected $fillable = [
        'payment_submission_id',
        'type',
        'status',
        'amount',
        'currency',
        'reason',
        'funds_received_at',
        'requested_by',
        'resolved_by',
        'requested_at',
        'resolved_at',
    ];

    protected $hidden = ['idempotency_key'];

    protected $casts = [
        'amount' => 'decimal:2',
        'type' => 'string',
        'status' => 'string',
        'currency' => 'string',
        'funds_received_at' => 'datetime',
        'requested_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $case): void {
            $case->status ??= self::OPEN;
            $case->currency ??= 'PEN';

            if ($case->type !== null && ! in_array($case->type, self::TYPES, true)) {
                throw new InvalidArgumentException('El tipo de resolución no es válido.');
            }
            if (! in_array($case->status, self::STATUSES, true) || $case->currency !== 'PEN') {
                throw new InvalidArgumentException('El estado o moneda de resolución no es válido.');
            }
        });
    }

    public function submission()
    {
        return $this->belongsTo(PaymentSubmission::class, 'payment_submission_id');
    }

    public function receivedPaymentTransaction()
    {
        return $this->belongsTo(PaymentTransaction::class, 'received_payment_transaction_id');
    }

    public function histories()
    {
        return $this->hasMany(PaymentResolutionHistory::class)->orderBy('occurred_at')->orderBy('id');
    }

    public function refund()
    {
        return $this->hasOne(PaymentRefund::class);
    }

    public function requestedBy()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function resolvedBy()
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function reservations()
    {
        return $this->hasMany(InventoryReservation::class, 'resolution_case_id');
    }
}
