<?php

namespace App\Services;

use App\Models\Setting;

class SettingsService
{
    public const CACHE_KEY = 'store.settings.1';

    public function get(): Setting
    {
        return cache()->remember(self::CACHE_KEY, 300, function () {
            return Setting::query()->firstOrCreate(['id' => 1], $this->defaults());
        });
    }

    public function fresh(): Setting
    {
        return Setting::query()->firstOrCreate(['id' => 1], $this->defaults());
    }

    public function update(array $data): Setting
    {
        $settings = $this->fresh();
        $settings->fill($data)->save();
        $this->forget();

        return $settings->refresh();
    }

    public function forget(): void
    {
        cache()->forget(self::CACHE_KEY);
    }

    /**
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        return [
            'id' => 1,
            'store_name' => 'Sonali Foshol Organics',
            'store_name_bn' => 'সোনালী ফসল অর্গানিকস',
            'brand_tagline_bn' => 'খাঁটি গ্রামের খাবার, সরাসরি কৃষকের কাছ থেকে',
            'whatsapp_number' => '8801712345678',
            'facebook_url' => 'https://facebook.com/sonalifoshol',
            'messenger_url' => 'https://m.me/sonalifoshol',
            'delivery_fee_inside' => 60,
            'delivery_fee_outside' => 120,
            'free_shipping_threshold' => 2000,
            'low_stock_threshold' => 10,
            'phone_display' => '+৮৮০ ১৭১২-৩৪৫৬৭৮',
            'email' => 'hello@sonalifoshol.com',
            'address_bn' => 'চিরিরবন্দর, দিনাজপুর, বাংলাদেশ',
            'inside_delivery_days' => 2,
            'outside_delivery_days' => 4,
        ];
    }
}
