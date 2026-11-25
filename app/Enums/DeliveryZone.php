<?php

namespace App\Enums;

enum DeliveryZone: string
{
    case InsideDhaka = 'inside_dhaka';
    case OutsideDhaka = 'outside_dhaka';

    public static function normalize(?string $value): ?self
    {
        return match ($value) {
            'inside_dhaka', 'inside-dhaka' => self::InsideDhaka,
            'outside_dhaka', 'outside-dhaka' => self::OutsideDhaka,
            default => null,
        };
    }

    public function publicValue(): string
    {
        return str_replace('_', '-', $this->value);
    }
}
