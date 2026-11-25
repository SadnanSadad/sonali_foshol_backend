<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;

class Media
{
    public static function url(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        return Storage::disk('public')->url($path);
    }

    public static function path(?string $value): ?string
    {
        if (! $value) {
            return null;
        }

        if (str_starts_with($value, 'http://') || str_starts_with($value, 'https://')) {
            $path = parse_url($value, PHP_URL_PATH) ?: '';

            return preg_replace('#^/(storage|images)/#', '', $path) ?: null;
        }

        return preg_replace('#^/(storage|images)/#', '', $value);
    }

    public static function ensurePublicDisk(): void
    {
        Storage::disk('public');
    }
}
