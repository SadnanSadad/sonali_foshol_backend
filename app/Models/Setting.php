<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'int';

    protected $fillable = [
        'id',
        'store_name',
        'store_name_bn',
        'brand_tagline_bn',
        'whatsapp_number',
        'facebook_url',
        'messenger_url',
        'delivery_fee_inside',
        'delivery_fee_outside',
        'free_shipping_threshold',
        'low_stock_threshold',
        'phone_display',
        'email',
        'address_bn',
        'inside_delivery_days',
        'outside_delivery_days',
    ];

    protected function casts(): array
    {
        return [
            'delivery_fee_inside' => 'integer',
            'delivery_fee_outside' => 'integer',
            'free_shipping_threshold' => 'integer',
            'low_stock_threshold' => 'integer',
            'inside_delivery_days' => 'integer',
            'outside_delivery_days' => 'integer',
        ];
    }
}
