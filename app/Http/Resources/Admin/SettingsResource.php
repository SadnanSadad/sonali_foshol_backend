<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SettingsResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'storeName' => $this->store_name,
            'storeNameBn' => $this->store_name_bn,
            'brandTaglineBn' => $this->brand_tagline_bn,
            'whatsappNumber' => $this->whatsapp_number,
            'facebookUrl' => $this->facebook_url,
            'messengerUrl' => $this->messenger_url,
            'deliveryFeeInside' => $this->delivery_fee_inside,
            'deliveryFeeOutside' => $this->delivery_fee_outside,
            'freeShippingThreshold' => $this->free_shipping_threshold,
            'lowStockThreshold' => $this->low_stock_threshold,
            'phoneDisplay' => $this->phone_display,
            'email' => $this->email,
            'addressBn' => $this->address_bn,
            'insideDeliveryDays' => $this->inside_delivery_days,
            'outsideDeliveryDays' => $this->outside_delivery_days,
        ];
    }
}
