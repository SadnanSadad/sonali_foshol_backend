<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderItem extends Model
{
    protected $fillable = [
        'order_id',
        'product_id',
        'product_weight_id',
        'product_slug',
        'product_name_bn',
        'weight_label',
        'grams',
        'unit_price',
        'qty',
        'line_total',
        'image_path',
    ];

    protected function casts(): array
    {
        return [
            'grams' => 'integer',
            'unit_price' => 'integer',
            'qty' => 'integer',
            'line_total' => 'integer',
        ];
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function weight()
    {
        return $this->belongsTo(ProductWeight::class, 'product_weight_id');
    }
}
