<?php

namespace App\Models;

use App\Enums\ReviewStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class Review extends Model
{
    use HasUlids;

    protected $fillable = [
        'product_id',
        'product_name_bn',
        'author',
        'location',
        'rating',
        'text',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'status' => ReviewStatus::class,
        ];
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
