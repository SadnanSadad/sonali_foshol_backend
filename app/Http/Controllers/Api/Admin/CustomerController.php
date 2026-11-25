<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\CustomerResource;
use App\Http\Resources\Admin\OrderResource;
use App\Models\Customer;
use App\Services\AuditService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly AuditService $audit) {}

    public function index(Request $request): JsonResponse
    {
        $limit = min(100, max(1, (int) $request->query('limit', 10)));

        $query = Customer::query()
            ->withCount('orders')
            ->withSum(['orders as orders_sum_total' => fn ($q) => $q->whereNotIn('status', ['rejected', 'cancelled'])], 'total')
            ->withMax('orders', 'created_at');

        if ($search = trim((string) $request->query('search'))) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('district', 'like', "%{$search}%");
            });
        }

        match ($request->query('flagged', 'all')) {
            'yes' => $query->where('flagged', true),
            'no' => $query->where('flagged', false),
            default => null,
        };

        match ($request->query('sort', 'last_order')) {
            'spent' => $query->orderByDesc('orders_sum_total'),
            'orders' => $query->orderByDesc('orders_count'),
            'name' => $query->orderBy('name'),
            default => $query->orderByDesc('orders_max_created_at')->orderByDesc('created_at'),
        };

        $paginator = $query->paginate($limit);

        return $this->paginated($paginator, CustomerResource::collection($paginator->getCollection())->resolve());
    }

    public function show(string $id): JsonResponse
    {
        $customer = Customer::query()
            ->withCount('orders')
            ->withSum(['orders as orders_sum_total' => fn ($q) => $q->whereNotIn('status', ['rejected', 'cancelled'])], 'total')
            ->withMax('orders', 'created_at')
            ->findOrFail($id);

        return $this->success(new CustomerResource($customer));
    }

    public function orders(string $id): JsonResponse
    {
        $customer = Customer::query()->findOrFail($id);

        $orders = $customer->orders()
            ->with(['items', 'statusHistory'])
            ->orderByDesc('created_at')
            ->get();

        return $this->success(OrderResource::collection($orders)->resolve());
    }

    public function flag(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['flagged' => ['required', 'boolean']]);
        $customer = Customer::query()->findOrFail($id);
        $customer->forceFill(['flagged' => $data['flagged']])->save();

        $this->audit->log('customer.flagged', Customer::class, $customer->id, ['flagged' => $data['flagged']], $request);

        $customer->loadCount('orders')
            ->loadSum(['orders as orders_sum_total' => fn ($q) => $q->whereNotIn('status', ['rejected', 'cancelled'])], 'total');

        return $this->success(new CustomerResource($customer));
    }
}
