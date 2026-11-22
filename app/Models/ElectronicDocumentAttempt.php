<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ElectronicDocumentAttempt extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'request_snapshot' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function document()
    {
        return $this->belongsTo(ElectronicDocument::class, 'electronic_document_id');
    }
}
