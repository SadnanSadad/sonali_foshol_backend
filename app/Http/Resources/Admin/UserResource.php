<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'username' => $this->username,
            'role' => $this->role->value,
            'avatar' => $this->avatar,
            'isActive' => (bool) $this->is_active,
            'lastLoginAt' => $this->last_login_at?->toISOString(),
            'twoFactorEnabled' => $this->two_factor_confirmed_at !== null,
        ];
    }
}
