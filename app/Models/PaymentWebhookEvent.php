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

    public const REVIEW_REQUIRED = 'review_required';

    public const FINANCIAL_REVIEW_REQUIRED = 'financial_review_required';

    public const MANUAL_RECONCILIATION_REQUIRED = 'manual_reconciliation_required';

    public const UNSUPPORTED_REMOTE_STATUS = 'unsupported_remote_status';

    public const REMOTE_PREFERENCE_UNAVAILABLE = 'remote_preference_id_unavailable';

    public const LOCAL_LINK_NOT_FOUND = 'local_link_not_found';

    public const LEGACY_IGNORE_REASON_UNKNOWN = 'legacy_ignore_reason_unknown';

    protected $fillable = ['gateway', 'provider_event_id', 'provider_payment_id', 'event_type', 'action', 'payload_hash', 'request_id_hash', 'idempotency_key', 'status', 'failure_reason', 'received_at', 'processing_started_at', 'processed_at'];

    protected $casts = ['received_at' => 'datetime', 'processing_started_at' => 'datetime', 'processed_at' => 'datetime'];
}
