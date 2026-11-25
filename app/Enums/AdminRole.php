<?php

namespace App\Enums;

enum AdminRole: string
{
    case Superadmin = 'superadmin';
    case Admin = 'admin';

    public function isSuperadmin(): bool
    {
        return $this === self::Superadmin;
    }
}
