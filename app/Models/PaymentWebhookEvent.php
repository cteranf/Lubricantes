<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentWebhookEvent extends Model
{
    public const RECEIVED = 'received';

    public const PROCESSING = 'processing';

    public const PROCESSED = 'processed';

    public const IGNORED = 'ignored';

    public const FAILED = 'failed';

    protected $fillable = ['gateway', 'provider_event_id', 'provider_payment_id', 'event_type', 'action', 'payload_hash', 'request_id_hash', 'idempotency_key', 'status', 'failure_reason', 'received_at', 'processing_started_at', 'processed_at'];

    protected $casts = ['received_at' => 'datetime', 'processing_started_at' => 'datetime', 'processed_at' => 'datetime'];
}
