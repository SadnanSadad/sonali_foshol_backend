<?php

namespace App\Http\Resources\Storefront;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'customerName' => $this->author,
            'location' => $this->location,
            'rating' => $this->rating,
            'commentBn' => $this->text,
        ];
    }
}
