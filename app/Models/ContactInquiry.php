<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ContactInquiry extends Model
{
    use HasFactory;

    public const PENDING = 'pending';
    public const IN_ATTENTION = 'in_attention';
    public const ATTENDED = 'attended';
    public const CLOSED = 'closed';
    public const SPAM = 'spam';

    public const STATUSES = [self::PENDING, self::IN_ATTENTION, self::ATTENDED, self::CLOSED, self::SPAM];
    public const COUNTER_STATUSES = [self::PENDING, self::IN_ATTENTION];

    public const TRANSITIONS = [
        self::PENDING => [self::IN_ATTENTION, self::SPAM],
        self::IN_ATTENTION => [self::ATTENDED, self::SPAM],
        self::ATTENDED => [self::CLOSED, self::IN_ATTENTION],
        self::CLOSED => [self::IN_ATTENTION],
        self::SPAM => [self::PENDING],
    ];

    protected $fillable = [
        'public_id', 'user_id', 'name', 'email', 'phone', 'subject', 'message', 'status',
        'assigned_to', 'submission_token', 'duplicate_hash', 'source', 'ip_hash',
        'attention_started_at', 'attended_at', 'closed_at', 'archived_at', 'last_activity_at',
    ];

    protected $casts = [
        'attention_started_at' => 'datetime',
        'attended_at' => 'datetime',
        'closed_at' => 'datetime',
        'archived_at' => 'datetime',
        'last_activity_at' => 'datetime',
    ];

    public function getRouteKeyName(): string { return 'public_id'; }
    public function user() { return $this->belongsTo(User::class); }
    public function assignee() { return $this->belongsTo(User::class, 'assigned_to'); }
    public function notes() { return $this->hasMany(ContactInquiryNote::class)->oldest(); }
    public function histories() { return $this->hasMany(ContactInquiryHistory::class)->oldest(); }

    public function canTransitionTo(string $status): bool
    {
        return in_array($status, self::TRANSITIONS[$this->status] ?? [], true);
    }
}
