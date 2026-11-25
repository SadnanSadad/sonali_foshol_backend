<?php

namespace App\Http\Resources\Storefront;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SettingsResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'brandNameBn' => $this->store_name_bn,
            'brandTaglineBn' => $this->brand_tagline_bn,
            'whatsappNumber' => $this->whatsapp_number,
            'facebookPageUrl' => $this->facebook_url,
            'messengerUrl' => $this->messenger_url,
            'insideDhakaDelivery' => $this->delivery_fee_inside,
            'outsideDhakaDelivery' => $this->delivery_fee_outside,
            'freeShippingThreshold' => $this->free_shipping_threshold,
            'phoneDisplay' => $this->phone_display,
            'email' => $this->email,
            'addressBn' => $this->address_bn,
        ];
    }
}
