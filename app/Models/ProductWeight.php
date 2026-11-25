<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductWeight extends Model
{
    protected $fillable = [
        'product_id',
        'label',
        'grams',
        'price',
        'mrp',
        'stock',
        'sku',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'grams' => 'integer',
            'price' => 'integer',
            'mrp' => 'integer',
            'stock' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
