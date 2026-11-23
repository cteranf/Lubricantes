<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ElectronicReceiptSummaryItem extends Model
{
    protected $guarded = ['id'];

    public function summary()
    {
        return $this->belongsTo(ElectronicReceiptSummary::class, 'electronic_receipt_summary_id');
    }

    public function document()
    {
        return $this->belongsTo(ElectronicDocument::class, 'electronic_document_id');
    }
}
