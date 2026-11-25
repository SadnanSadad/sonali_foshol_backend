<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\SettingsResource;
use App\Services\AuditService;
use App\Services\SettingsService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly SettingsService $settings,
        private readonly AuditService $audit,
    ) {}

    public function show(): JsonResponse
    {
        return $this->success(new SettingsResource($this->settings->get()));
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'storeName' => ['sometimes', 'string', 'max:200'],
            'storeNameBn' => ['sometimes', 'string', 'max:200'],
            'brandTaglineBn' => ['sometimes', 'string', 'max:500'],
            'whatsappNumber' => ['sometimes', 'string', 'max:30'],
            'facebookUrl' => ['nullable', 'url:https'],
            'messengerUrl' => ['nullable', 'url:https'],
            'deliveryFeeInside' => ['sometimes', 'integer', 'min:0'],
            'deliveryFeeOutside' => ['sometimes', 'integer', 'min:0'],
            'freeShippingThreshold' => ['sometimes', 'integer', 'min:0'],
            'lowStockThreshold' => ['sometimes', 'integer', 'min:0'],
            'phoneDisplay' => ['sometimes', 'string', 'max:40'],
            'email' => ['sometimes', 'email', 'max:190'],
            'addressBn' => ['sometimes', 'string', 'max:500'],
            'insideDeliveryDays' => ['sometimes', 'integer', 'min:1', 'max:30'],
            'outsideDeliveryDays' => ['sometimes', 'integer', 'min:1', 'max:60'],
        ]);

        if (isset($data['whatsappNumber'])) {
            $digits = preg_replace('/\D+/', '', $data['whatsappNumber']);
            if (str_starts_with($digits, '8801')) {
                $digits = '0'.substr($digits, 3);
            }
            $data['whatsappNumber'] = $digits;
            $request->merge(['whatsappNumber' => $digits]);
            validator(['whatsappNumber' => $digits], ['whatsappNumber' => ['required', 'digits:11', 'regex:/^01[3-9]\d{8}$/']])->validate();
        }

        $map = [
            'storeName' => 'store_name',
            'storeNameBn' => 'store_name_bn',
            'brandTaglineBn' => 'brand_tagline_bn',
            'whatsappNumber' => 'whatsapp_number',
            'facebookUrl' => 'facebook_url',
            'messengerUrl' => 'messenger_url',
            'deliveryFeeInside' => 'delivery_fee_inside',
            'deliveryFeeOutside' => 'delivery_fee_outside',
            'freeShippingThreshold' => 'free_shipping_threshold',
            'lowStockThreshold' => 'low_stock_threshold',
            'phoneDisplay' => 'phone_display',
            'email' => 'email',
            'addressBn' => 'address_bn',
            'insideDeliveryDays' => 'inside_delivery_days',
            'outsideDeliveryDays' => 'outside_delivery_days',
        ];

        $attributes = [];
        foreach ($map as $api => $column) {
            if (array_key_exists($api, $data)) {
                $attributes[$column] = $data[$api];
            }
        }

        $settings = $this->settings->update($attributes);

        $this->audit->log('settings.updated', null, null, array_keys($data), $request);

        return $this->success(new SettingsResource($settings));
    }
}
