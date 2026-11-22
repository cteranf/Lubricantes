<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ElectronicDocumentOutbox extends Model
{
    protected $table = 'electronic_document_outbox';

    protected $guarded = ['id'];

    protected $casts = ['published_at' => 'datetime'];

    public function attempt()
    {
        return $this->belongsTo(ElectronicDocumentAttempt::class, 'electronic_document_attempt_id');
    }
}
