<?php

namespace App\Http\Resources\Storefront;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderTrackingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'orderNumber' => $this->order_number,
            'status' => $this->status->value,
            'createdAt' => $this->created_at?->toISOString(),
            'expectedDeliveryDate' => $this->expected_delivery_date?->toISOString(),
            'customer' => [
                'name' => $this->customer_name,
                'district' => $this->district,
            ],
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'slug' => $item->product_slug,
                'title' => $item->product_name_bn,
                'weightLabel' => $item->weight_label,
                'grams' => $item->grams,
                'unitPrice' => $item->unit_price,
                'qty' => $item->qty,
            ])->all()),
            'subtotal' => $this->subtotal,
            'deliveryFee' => $this->delivery_fee,
            'total' => $this->total,
            'statusHistory' => $this->whenLoaded('statusHistory', fn () => $this->statusHistory->map(fn ($entry) => [
                'status' => $entry->status,
                'at' => $entry->created_at?->toISOString(),
                'note' => $entry->note,
            ])->all()),
        ];
    }
}
