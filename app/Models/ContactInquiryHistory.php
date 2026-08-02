<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContactInquiryHistory extends Model
{
    public $timestamps = false;
    protected $fillable = ['contact_inquiry_id', 'actor_id', 'event_type', 'from_status', 'to_status', 'metadata', 'created_at'];
    protected $casts = ['metadata' => 'array', 'created_at' => 'datetime'];
    public function inquiry() { return $this->belongsTo(ContactInquiry::class, 'contact_inquiry_id'); }
    public function actor() { return $this->belongsTo(User::class, 'actor_id'); }
}
