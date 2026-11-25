<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CalendarEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'type' => $this->type,
            'start' => $this->start_at?->toISOString(),
            'end' => $this->end_at?->toISOString(),
            'allDay' => (bool) $this->all_day,
            'note' => $this->note,
            'orderId' => $this->order_id,
        ];
    }
}
