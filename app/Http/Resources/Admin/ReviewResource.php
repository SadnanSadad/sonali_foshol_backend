<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'productId' => $this->product_id,
            'productNameBn' => $this->product_name_bn,
            'author' => $this->author,
            'location' => $this->location,
            'rating' => $this->rating,
            'text' => $this->text,
            'status' => $this->status->value,
            'createdAt' => $this->created_at?->toISOString(),
        ];
    }
}
