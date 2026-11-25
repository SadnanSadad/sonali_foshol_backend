<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\SettingsService;
use App\Support\ApiResponse;
use App\Support\Media;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly SettingsService $settings) {}

    public function summary(): JsonResponse
    {
        $settings = $this->settings->get();
        $now = now('Asia/Dhaka');

        $todayStart = $now->copy()->startOfDay()->utc();
        $todayEnd = $now->copy()->endOfDay()->utc();

        $todayOrders = Order::query()->whereBetween('created_at', [$todayStart, $todayEnd])->count();
        $todayRevenue = (int) Order::query()
            ->whereBetween('created_at', [$todayStart, $todayEnd])
            ->whereNotIn('status', [OrderStatus::Rejected->value, OrderStatus::Cancelled->value])
            ->sum('total');

        $pendingOrders = Order::query()->where('status', OrderStatus::Pending->value)->count();

        $lowStockCount = DB::table('product_weights')
            ->join('products', 'products.id', '=', 'product_weights.product_id')
            ->whereNull('products.deleted_at')
            ->where('product_weights.stock', '<=', $settings->low_stock_threshold)
            ->distinct('product_weights.product_id')
            ->count('product_weights.product_id');

        $counted = Order::query()->whereNotIn('status', [OrderStatus::Rejected->value, OrderStatus::Cancelled->value]);
        $revenue = (int) (clone $counted)->sum('total');
        $orderCount = (clone $counted)->count();

        $last30Start = now()->subDays(30);
        $prev30Start = now()->subDays(60);

        $last30Revenue = (int) (clone $counted)->where('created_at', '>=', $last30Start)->sum('total');
        $prev30Revenue = (int) (clone $counted)->whereBetween('created_at', [$prev30Start, $last30Start])->sum('total');
        $last30Count = (clone $counted)->where('created_at', '>=', $last30Start)->count();
        $prev30Count = (clone $counted)->whereBetween('created_at', [$prev30Start, $last30Start])->count();

        $change = fn (int|float $current, int|float $previous) => $previous === 0
            ? ($current > 0 ? 100.0 : 0.0)
            : round((($current - $previous) / $previous) * 100, 1);

        return $this->success([
            'todayOrders' => $todayOrders,
            'todayRevenue' => $todayRevenue,
            'pendingOrders' => $pendingOrders,
            'lowStockCount' => $lowStockCount,
            'revenue' => $revenue,
            'orderCount' => $orderCount,
            'averageOrderValue' => $orderCount ? (int) round($revenue / $orderCount) : 0,
            'revenueChangePct' => $change($last30Revenue, $prev30Revenue),
            'orderChangePct' => $change($last30Count, $prev30Count),
        ]);
    }

    public function sales(Request $request): JsonResponse
    {
        $range = $request->query('range', '30d');
        $days = match ($range) {
            '7d' => 7,
            '90d' => 90,
            default => 30,
        };

        $start = now('Asia/Dhaka')->subDays($days - 1)->startOfDay()->utc();
        $end = now('Asia/Dhaka')->endOfDay()->utc();

        $rows = Order::query()
            ->whereBetween('created_at', [$start, $end])
            ->whereNotIn('status', [OrderStatus::Rejected->value, OrderStatus::Cancelled->value])
            ->get(['created_at', 'total']);

        $grouped = $rows->groupBy(fn (Order $order) => $order->created_at->copy()->setTimezone('Asia/Dhaka')->format('Y-m-d'));

        $points = [];
        for ($i = 0; $i < $days; $i++) {
            $date = now('Asia/Dhaka')->subDays($days - 1 - $i)->format('Y-m-d');
            $items = $grouped->get($date, collect());
            $points[] = [
                'date' => $date,
                'revenue' => (int) $items->sum('total'),
                'orders' => $items->count(),
            ];
        }

        return $this->success($points);
    }

    public function statusBreakdown(): JsonResponse
    {
        $rows = Order::query()
            ->select('status', DB::raw('count(*) as count'), DB::raw('sum(total) as total'))
            ->groupBy('status')
            ->get()
            ->keyBy(fn ($row) => $row->status instanceof OrderStatus ? $row->status->value : $row->status);

        $result = [];
        foreach (OrderStatus::cases() as $status) {
            $row = $rows->get($status->value);
            $result[] = [
                'status' => $status->value,
                'count' => (int) ($row->count ?? 0),
                'total' => (int) ($row->total ?? 0),
            ];
        }

        return $this->success($result);
    }

    public function topProducts(Request $request): JsonResponse
    {
        $limit = min(20, max(1, (int) $request->query('limit', 5)));

        $rows = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereNotIn('orders.status', [OrderStatus::Rejected->value, OrderStatus::Cancelled->value])
            ->groupBy('order_items.product_id', 'order_items.product_slug', 'order_items.product_name_bn', 'order_items.image_path')
            ->select([
                'order_items.product_id',
                'order_items.product_slug',
                'order_items.product_name_bn',
                'order_items.image_path',
                DB::raw('sum(order_items.qty) as units_sold'),
                DB::raw('sum(order_items.line_total) as revenue'),
            ])
            ->orderByDesc('units_sold')
            ->limit($limit)
            ->get();

        return $this->success($rows->map(fn ($row) => [
            'productId' => $row->product_id,
            'nameBn' => $row->product_name_bn,
            'slug' => $row->product_slug,
            'image' => Media::url($row->image_path),
            'unitsSold' => (int) $row->units_sold,
            'revenue' => (int) $row->revenue,
        ])->all());
    }
}
