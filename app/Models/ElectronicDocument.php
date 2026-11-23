<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ElectronicDocument extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'seller_snapshot' => 'array',
        'recipient_snapshot' => 'array',
        'lines_snapshot' => 'array',
        'amounts_snapshot' => 'array',
        'prepared_at' => 'datetime',
        'last_modified_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'accepted_at' => 'datetime',
    ];

    public function events()
    {
        return $this->hasMany(ElectronicDocumentEvent::class)->orderBy('id');
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function attempts()
    {
        return $this->hasMany(ElectronicDocumentAttempt::class);
    }

    public function receiptSummaryItem()
    {
        return $this->hasOne(ElectronicReceiptSummaryItem::class, 'electronic_document_id');
    }
}
