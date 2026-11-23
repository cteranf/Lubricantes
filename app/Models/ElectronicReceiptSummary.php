<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ElectronicReceiptSummary extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['issue_date' => 'date', 'snapshot' => 'array', 'confirmed_at' => 'datetime'];

    public function items()
    {
        return $this->hasMany(ElectronicReceiptSummaryItem::class, 'electronic_receipt_summary_id')->orderBy('line_number');
    }

    public function attempts()
    {
        return $this->hasMany(ElectronicReceiptSummaryAttempt::class, 'electronic_receipt_summary_id')->orderBy('id');
    }

    public function events()
    {
        return $this->hasMany(ElectronicReceiptSummaryEvent::class, 'electronic_receipt_summary_id')->orderBy('id');
    }
}
