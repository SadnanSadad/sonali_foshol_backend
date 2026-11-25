<?php

namespace App\Models;

use App\Enums\ProductStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use HasUlids, SoftDeletes;

    protected $fillable = [
        'category_id',
        'slug',
        'name_bn',
        'name_en',
        'origin_bn',
        'short_desc_bn',
        'full_desc_bn',
        'image_path',
        'badge_bn',
        'in_stock',
        'status',
        'nutrition_highlights',
        'nutrition_facts',
        'cooking_tips_bn',
    ];

    protected function casts(): array
    {
        return [
            'in_stock' => 'boolean',
            'status' => ProductStatus::class,
            'nutrition_highlights' => 'array',
            'nutrition_facts' => 'array',
        ];
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function images()
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort_order');
    }

    public function weights()
    {
        return $this->hasMany(ProductWeight::class)->orderBy('sort_order');
    }

    public function reviews()
    {
        return $this->hasMany(Review::class);
    }

    public function totalStock(): int
    {
        return (int) $this->weights->sum('stock');
    }

    public function isPubliclyAvailable(): bool
    {
        return $this->status === ProductStatus::Active
            && $this->in_stock
            && $this->totalStock() > 0;
    }
}
