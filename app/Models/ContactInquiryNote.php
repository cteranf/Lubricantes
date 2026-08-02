<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContactInquiryNote extends Model
{
    protected $fillable = ['contact_inquiry_id', 'user_id', 'body'];
    public function inquiry() { return $this->belongsTo(ContactInquiry::class, 'contact_inquiry_id'); }
    public function user() { return $this->belongsTo(User::class); }
}
