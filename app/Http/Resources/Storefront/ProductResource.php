<?php

namespace App\Http\Resources\Storefront;

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
            'inStock' => $this->available(),
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
            ])->all()),
        ];
    }

    private function available(): bool
    {
        return $this->status->value === 'active'
            && (bool) $this->in_stock
            && (int) $this->weights->sum('stock') > 0;
    }
}
