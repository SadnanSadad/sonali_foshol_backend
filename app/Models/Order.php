<?php

namespace App\Models;

use App\Enums\DeliveryZone;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    use HasUlids;

    protected $fillable = [
        'order_number',
        'client_request_id',
        'customer_id',
        'status',
        'payment_method',
        'delivery_zone',
        'customer_name',
        'customer_phone',
        'customer_email',
        'district',
        'address',
        'note',
        'subtotal',
        'delivery_fee',
        'discount',
        'total',
        'expected_delivery_date',
        'placed_by',
        'rejection_reason',
        'rejection_feedback',
        'rejected_by',
        'rejected_at',
        'courier_name',
        'courier_tracking_id',
        'stock_restored_at',
        'ip_address',
        'created_at',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'payment_method' => PaymentMethod::class,
            'delivery_zone' => DeliveryZone::class,
            'subtotal' => 'integer',
            'delivery_fee' => 'integer',
            'discount' => 'integer',
            'total' => 'integer',
            'expected_delivery_date' => 'datetime',
            'rejected_at' => 'datetime',
            'stock_restored_at' => 'datetime',
        ];
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function statusHistory()
    {
        return $this->hasMany(OrderStatusHistory::class)->orderBy('created_at');
    }

    public function calendarEvents()
    {
        return $this->hasMany(CalendarEvent::class);
    }
}
