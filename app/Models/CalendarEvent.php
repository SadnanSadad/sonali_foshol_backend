<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class CalendarEvent extends Model
{
    use HasUlids;

    protected $fillable = [
        'title',
        'type',
        'start_at',
        'end_at',
        'all_day',
        'note',
        'order_id',
    ];

    protected function casts(): array
    {
        return [
            'start_at' => 'datetime',
            'end_at' => 'datetime',
            'all_day' => 'boolean',
        ];
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}
