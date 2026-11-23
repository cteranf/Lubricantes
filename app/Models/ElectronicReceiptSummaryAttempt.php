<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ElectronicReceiptSummaryAttempt extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['request_snapshot' => 'array', 'started_at' => 'datetime', 'completed_at' => 'datetime'];

    public function summary()
    {
        return $this->belongsTo(ElectronicReceiptSummary::class, 'electronic_receipt_summary_id');
    }
}
