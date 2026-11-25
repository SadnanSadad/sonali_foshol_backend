<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class Customer extends Authenticatable
{
    use HasUlids, Notifiable;

    protected $fillable = [
        'name',
        'phone',
        'email',
        'password',
        'district',
        'address',
        'flagged',
        'last_order_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'flagged' => 'boolean',
            'last_order_at' => 'datetime',
            'email_verified_at' => 'datetime',
        ];
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }
}
