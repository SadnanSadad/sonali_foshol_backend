<?php

namespace App\Http\Resources\Storefront;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'orderId' => $this->order_number,
            'orderNumber' => $this->order_number,
            'id' => $this->id,
            'createdAt' => $this->created_at?->toISOString(),
            'customer' => [
                'name' => $this->customer_name,
                'phone' => $this->customer_phone,
                'email' => $this->customer_email,
                'district' => $this->district,
                'address' => $this->address,
                'note' => $this->note,
            ],
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'productId' => $item->product_id,
                'slug' => $item->product_slug,
                'title' => $item->product_name_bn,
                'weightLabel' => $item->weight_label,
                'grams' => $item->grams,
                'unitPrice' => $item->unit_price,
                'qty' => $item->qty,
            ])->all()),
            'deliveryZone' => $this->delivery_zone->publicValue(),
            'paymentMethod' => $this->payment_method->value,
            'subtotal' => $this->subtotal,
            'deliveryFee' => $this->delivery_fee,
            'total' => $this->total,
        ];
    }
}
