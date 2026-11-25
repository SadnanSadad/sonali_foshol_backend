<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'nameEn' => $this->name_en,
            'nameBn' => $this->name_bn,
            'order' => $this->sort_order,
            'hidden' => (bool) $this->hidden,
            'productCount' => $this->when(isset($this->products_count), fn () => (int) $this->products_count),
        ];
    }
}
