<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'can_deliver',
        'is_active',
        'phone',
        'addresses',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'addresses' => 'array',
        'can_deliver' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function isAdmin()
    {
        return $this->role === 'admin';
    }

    public function isTreasury(): bool
    {
        return $this->role === 'treasury';
    }

    /**
     * Models created before the pending state migration do not receive the
     * database default in Eloquent's in-memory attributes. Treat that absence
     * as active; the persisted column remains the source of truth once applied.
     */
    public function getIsActiveAttribute($value): bool
    {
        return $value === null ? true : (bool) $value;
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    public function savedAddresses()
    {
        return $this->hasMany(UserAddress::class);
    }

    public function assignedContactInquiries()
    {
        return $this->hasMany(ContactInquiry::class, 'assigned_to');
    }
}
