<?php

namespace App\Http\Controllers\Api\Storefront;

use App\Http\Controllers\Controller;
use App\Http\Resources\Storefront\OrderResource;
use App\Http\Resources\Storefront\OrderTrackingResource;
use App\Models\Order;
use App\Services\OrderService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OrderController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly OrderService $orders) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'clientRequestId' => ['nullable', 'uuid'],
            'customer' => ['required', 'array'],
            'customer.name' => ['required', 'string', 'min:3', 'max:120'],
            'customer.phone' => ['required', 'string', 'max:20'],
            'customer.email' => ['nullable', 'email', 'max:190'],
            'customer.district' => ['required', 'string', 'max:120'],
            'customer.address' => ['required', 'string', 'min:10', 'max:1000'],
            'customer.note' => ['nullable', 'string', 'max:300'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.productId' => ['nullable', 'string', 'max:26'],
            'items.*.slug' => ['nullable', 'string', 'max:160'],
            'items.*.grams' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'items.*.weightLabel' => ['nullable', 'string', 'max:80'],
            'items.*.qty' => ['required', 'integer', 'min:1', 'max:99'],
            'deliveryZone' => ['required', 'string'],
            'paymentMethod' => ['required', Rule::in(['cod', 'whatsapp', 'messenger'])],
        ]);

        foreach ($data['items'] as $index => $item) {
            if (empty($item['productId']) && empty($item['slug'])) {
                throw ValidationException::withMessages(["items.{$index}" => ['A product reference is required.']]);
            }
            if (empty($item['grams']) && empty($item['weightLabel'])) {
                throw ValidationException::withMessages(["items.{$index}" => ['A weight selection is required.']]);
            }
        }

        $phone = OrderService::normalizePhone($data['customer']['phone']);
        if (! preg_match('/^01[3-9]\d{8}$/', $phone)) {
            throw ValidationException::withMessages(['customer.phone' => ['The phone number must be a valid Bangladeshi mobile number.']]);
        }

        $data['customer']['phone'] = $phone;
        $data['deliveryZone'] = \App\Enums\DeliveryZone::normalize($data['deliveryZone'])?->publicValue();

        $order = $this->orders->create($data, $request->user('customer'), $request->ip());

        return $this->success(new OrderResource($order), $order->wasRecentlyCreated ? 201 : 200);
    }

    public function lookup(Request $request): JsonResponse
    {
        $data = $request->validate([
            'orderNumber' => ['nullable', 'string', 'max:40'],
            'phone' => ['required', 'string', 'max:20'],
        ]);

        $phone = OrderService::normalizePhone($data['phone']);
        if (! preg_match('/^01[3-9]\d{8}$/', $phone)) {
            throw ValidationException::withMessages(['phone' => ['The phone number must be a valid Bangladeshi mobile number.']]);
        }

        $query = Order::query()
            ->where('customer_phone', $phone)
            ->with(['items', 'statusHistory']);

        if (! empty($data['orderNumber'])) {
            $order = $query
                ->where('order_number', strtoupper(trim($data['orderNumber'])))
                ->first();

            if (! $order) {
                return $this->error('Order not found.', 404);
            }

            return $this->success(new OrderTrackingResource($order));
        }

        $orders = $query->orderByDesc('created_at')->get();

        return $this->success(OrderTrackingResource::collection($orders));
    }

    public function index(Request $request): JsonResponse
    {
        $limit = min(50, max(1, (int) $request->query('limit', 10)));

        $paginator = Order::query()
            ->where('customer_id', $request->user('customer')->id)
            ->with(['items', 'statusHistory'])
            ->orderByDesc('created_at')
            ->paginate($limit);

        return $this->paginated($paginator, OrderResource::collection($paginator->getCollection())->resolve());
    }

    public function show(Request $request, string $orderNumber): JsonResponse
    {
        $order = Order::query()
            ->where('customer_id', $request->user('customer')->id)
            ->where('order_number', strtoupper(trim($orderNumber)))
            ->with(['items', 'statusHistory'])
            ->firstOrFail();

        return $this->success(new OrderResource($order));
    }
}
