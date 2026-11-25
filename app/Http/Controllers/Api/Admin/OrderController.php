<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\DeliveryZone;
use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\OrderResource;
use App\Models\Order;
use App\Services\OrderService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OrderController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly OrderService $orders) {}

    public function index(Request $request): JsonResponse
    {
        $limit = min(100, max(1, (int) $request->query('limit', 10)));

        $query = Order::query()->with(['items', 'statusHistory']);

        if ($status = $request->query('status')) {
            $statuses = array_values(array_filter(explode(',', (string) $status)));
            if ($statuses !== [] && ! in_array('all', $statuses, true)) {
                $query->whereIn('status', $statuses);
            }
        }

        if ($search = trim((string) $request->query('search'))) {
            $query->where(function ($q) use ($search) {
                $q->where('order_number', 'like', "%{$search}%")
                    ->orWhere('customer_name', 'like', "%{$search}%")
                    ->orWhere('customer_phone', 'like', "%{$search}%");
            });
        }

        if ($from = $request->query('from')) {
            $query->where('created_at', '>=', Carbon::parse($from, 'Asia/Dhaka')->startOfDay()->utc());
        }

        if ($to = $request->query('to')) {
            $query->where('created_at', '<=', Carbon::parse($to, 'Asia/Dhaka')->endOfDay()->utc());
        }

        if ($zone = DeliveryZone::normalize($request->query('zone'))) {
            $query->where('delivery_zone', $zone);
        }

        if ($payment = $request->query('payment')) {
            if (in_array($payment, ['cod', 'whatsapp', 'messenger'], true)) {
                $query->where('payment_method', $payment);
            }
        }

        $paginator = $query->orderByDesc('created_at')->paginate($limit);

        return $this->paginated($paginator, OrderResource::collection($paginator->getCollection())->resolve());
    }

    public function show(string $id): JsonResponse
    {
        $order = Order::query()
            ->with(['items', 'statusHistory'])
            ->where('id', $id)
            ->orWhere('order_number', $id)
            ->firstOrFail();

        return $this->success(new OrderResource($order));
    }

    public function updateStatus(Request $request, string $id): JsonResponse
    {
        $order = Order::query()->with(['items', 'statusHistory'])->findOrFail($id);

        $data = $request->validate([
            'status' => ['required', Rule::enum(OrderStatus::class)],
            'reason' => ['nullable', Rule::in(['out_of_stock', 'unreachable', 'invalid_address', 'fake_order', 'outside_area', 'other'])],
            'feedback' => ['nullable', 'string', 'max:2000'],
            'courier' => ['nullable', 'string', 'max:120'],
            'trackingId' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $next = OrderStatus::from($data['status']);

        if ($next === OrderStatus::Rejected && empty($data['reason'])) {
            throw ValidationException::withMessages(['reason' => ['A rejection reason is required.']]);
        }

        $updated = $this->orders->transition($order, $next, $data, $request->user('admin'));

        return $this->success(new OrderResource($updated));
    }
}
