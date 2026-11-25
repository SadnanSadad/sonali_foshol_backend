<?php

namespace App\Http\Resources\Admin;

use App\Support\Media;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'orderNumber' => $this->order_number,
            'createdAt' => $this->created_at?->toISOString(),
            'expectedDeliveryDate' => $this->expected_delivery_date?->toISOString(),
            'status' => $this->status->value,
            'customer' => [
                'name' => $this->customer_name,
                'phone' => $this->customer_phone,
                'district' => $this->district,
                'address' => $this->address,
                'note' => $this->note,
            ],
            'deliveryZone' => $this->delivery_zone->value,
            'paymentMethod' => $this->payment_method->value,
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'productId' => $item->product_id,
                'slug' => $item->product_slug,
                'title' => $item->product_name_bn,
                'image' => Media::url($item->image_path),
                'weightLabel' => $item->weight_label,
                'unitPrice' => $item->unit_price,
                'qty' => $item->qty,
            ])->all()),
            'subtotal' => $this->subtotal,
            'deliveryFee' => $this->delivery_fee,
            'discount' => $this->discount,
            'total' => $this->total,
            'rejection' => $this->status->value === 'rejected' ? [
                'reason' => $this->rejection_reason,
                'feedback' => $this->rejection_feedback,
                'by' => $this->rejected_by,
                'at' => $this->rejected_at?->toISOString(),
            ] : null,
            'courier' => $this->courier_name ? [
                'name' => $this->courier_name,
                'trackingId' => $this->courier_tracking_id,
            ] : null,
            'statusHistory' => $this->whenLoaded('statusHistory', fn () => $this->statusHistory->map(fn ($entry) => [
                'status' => $entry->status,
                'at' => $entry->created_at?->toISOString(),
                'by' => $entry->changed_by_name,
                'note' => $entry->note,
            ])->all()),
        ];
    }
}
