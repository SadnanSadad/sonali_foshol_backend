<?php

namespace App\Http\Resources\Admin;

use App\Support\Media;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'nameBn' => $this->name_bn,
            'nameEn' => $this->name_en,
            'category' => $this->whenLoaded('category', fn () => $this->category->slug),
            'originBn' => $this->origin_bn,
            'image' => Media::url($this->image_path),
            'images' => $this->whenLoaded('images', fn () => $this->images->map(fn ($image) => Media::url($image->path))->all()),
            'badgeBn' => $this->badge_bn,
            'inStock' => (bool) $this->in_stock,
            'stock' => (int) ($this->weights_sum_stock ?? ($this->relationLoaded('weights') ? $this->weights->sum('stock') : 0)),
            'status' => $this->status->value,
            'shortDescBn' => $this->short_desc_bn,
            'fullDescBn' => $this->full_desc_bn,
            'nutritionHighlights' => $this->nutrition_highlights ?? [],
            'nutritionFacts' => $this->nutrition_facts ?? [],
            'cookingTipsBn' => $this->cooking_tips_bn,
            'weights' => $this->whenLoaded('weights', fn () => $this->weights->map(fn ($weight) => [
                'label' => $weight->label,
                'grams' => $weight->grams,
                'price' => $weight->price,
                'mrp' => $weight->mrp,
                'stock' => $weight->stock,
                'sku' => $weight->sku,
            ])->all()),
            'createdAt' => $this->created_at?->toISOString(),
            'updatedAt' => $this->updated_at?->toISOString(),
        ];
    }
}
