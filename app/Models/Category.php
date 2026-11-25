<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    use HasUlids;

    protected $fillable = [
        'slug',
        'name_en',
        'name_bn',
        'sort_order',
        'hidden',
    ];

    protected function casts(): array
    {
        return [
            'hidden' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function products()
    {
        return $this->hasMany(Product::class);
    }
}
