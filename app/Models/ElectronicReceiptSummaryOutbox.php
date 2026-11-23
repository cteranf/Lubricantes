<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ElectronicReceiptSummaryOutbox extends Model
{
    protected $table = 'electronic_receipt_summary_outbox';

    protected $guarded = ['id'];

    protected $casts = ['published_at' => 'datetime'];

    public function attempt()
    {
        return $this->belongsTo(ElectronicReceiptSummaryAttempt::class, 'electronic_receipt_summary_attempt_id');
    }
}
