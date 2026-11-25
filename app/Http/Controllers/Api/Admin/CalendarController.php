<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\CalendarEventResource;
use App\Models\CalendarEvent;
use App\Models\Order;
use App\Services\AuditService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class CalendarController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly AuditService $audit) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $from = Carbon::parse($request->query('from', now('Asia/Dhaka')->startOfMonth()->toDateString()), 'Asia/Dhaka')->startOfDay();
        $to = Carbon::parse($request->query('to', now('Asia/Dhaka')->endOfMonth()->toDateString()), 'Asia/Dhaka')->endOfDay();

        $events = CalendarEvent::query()
            ->whereBetween('start_at', [$from->copy()->utc(), $to->copy()->utc()])
            ->orderBy('start_at')
            ->get();

        $deliveries = Order::query()
            ->whereBetween('expected_delivery_date', [$from->copy()->utc(), $to->copy()->utc()])
            ->orderBy('expected_delivery_date')
            ->get()
            ->map(fn (Order $order) => [
                'id' => 'delivery-'.$order->id,
                'title' => $order->order_number.' — '.$order->customer_name,
                'type' => 'delivery',
                'start' => $order->expected_delivery_date?->toISOString(),
                'end' => null,
                'allDay' => true,
                'note' => $order->district,
                'orderId' => $order->id,
            ])
            ->all();

        $admin = CalendarEventResource::collection($events)->resolve();

        return $this->success([...$admin, ...$deliveries]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        $event = CalendarEvent::query()->create([
            'title' => $data['title'],
            'type' => 'admin',
            'start_at' => Carbon::parse($data['start'])->utc(),
            'end_at' => isset($data['end']) ? Carbon::parse($data['end'])->utc() : null,
            'all_day' => (bool) ($data['allDay'] ?? false),
            'note' => $data['note'] ?? null,
            'order_id' => $data['orderId'] ?? null,
        ]);

        $this->audit->log('calendar.created', CalendarEvent::class, $event->id, ['title' => $event->title], $request);

        return $this->success(new CalendarEventResource($event), 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        if (str_starts_with($id, 'delivery-')) {
            $order = Order::query()->findOrFail(substr($id, 9));
            $data = $request->validate(['start' => ['nullable', 'date']]);

            if (! empty($data['start'])) {
                $order->expected_delivery_date = Carbon::parse($data['start'])->utc();
                $order->save();
            }

            return $this->success([
                'id' => $id,
                'title' => $order->order_number,
                'type' => 'delivery',
                'start' => $order->expected_delivery_date?->toISOString(),
                'end' => null,
                'allDay' => true,
                'note' => $order->district,
                'orderId' => $order->id,
            ]);
        }

        $event = CalendarEvent::query()->findOrFail($id);
        $data = $this->validated($request, true);

        $event->fill([
            'title' => $data['title'] ?? $event->title,
            'start_at' => isset($data['start']) ? Carbon::parse($data['start'])->utc() : $event->start_at,
            'end_at' => array_key_exists('end', $data) ? ($data['end'] ? Carbon::parse($data['end'])->utc() : null) : $event->end_at,
            'all_day' => $data['allDay'] ?? $event->all_day,
            'note' => $data['note'] ?? $event->note,
            'order_id' => $data['orderId'] ?? $event->order_id,
        ])->save();

        $this->audit->log('calendar.updated', CalendarEvent::class, $event->id, ['title' => $event->title], $request);

        return $this->success(new CalendarEventResource($event));
    }

    public function destroy(string $id): JsonResponse
    {
        if (! str_starts_with($id, 'delivery-')) {
            CalendarEvent::query()->findOrFail($id)->delete();
        }

        return $this->success(['success' => true]);
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'title' => [$required, 'string', 'max:200'],
            'start' => [$required, 'date'],
            'end' => ['nullable', 'date', 'after_or_equal:start'],
            'allDay' => ['sometimes', 'boolean'],
            'note' => ['nullable', 'string', 'max:2000'],
            'orderId' => ['nullable', Rule::exists('orders', 'id')],
        ]);
    }
}
