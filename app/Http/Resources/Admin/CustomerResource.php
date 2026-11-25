<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

class CustomerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $orderCount = $this->orders_count ?? $this->orders()->count();
        $totalSpent = $this->orders_sum_total ?? (int) $this->orders()->whereNotIn('status', ['rejected', 'cancelled'])->sum('total');
        $lastOrderAt = $this->orders_max_created_at ?? $this->last_order_at;
        $lastOrderAt = $lastOrderAt instanceof \DateTimeInterface ? $lastOrderAt : ($lastOrderAt ? Carbon::parse($lastOrderAt) : null);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'phone' => $this->phone,
            'district' => $this->district,
            'address' => $this->address,
            'orderCount' => (int) $orderCount,
            'totalSpent' => (int) $totalSpent,
            'flagged' => (bool) $this->flagged,
            'createdAt' => $this->created_at?->toISOString(),
            'lastOrderAt' => $lastOrderAt?->toISOString(),
        ];
    }
}
