<?php

namespace App\Services;

use App\Enums\DeliveryZone;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\ProductStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductWeight;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderService
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly OrderNumberService $orderNumbers,
        private readonly AuditService $audit,
    ) {}

    public function create(array $payload, ?Customer $account = null, ?string $ip = null): Order
    {
        $requestId = $payload['clientRequestId'] ?? null;

        if ($requestId) {
            $existing = Order::query()->where('client_request_id', $requestId)->first();
            if ($existing) {
                return $existing->load(['items', 'statusHistory']);
            }
        }

        $phone = self::normalizePhone($payload['customer']['phone'] ?? '');
        $zone = DeliveryZone::normalize($payload['deliveryZone'] ?? null);

        if (! $zone) {
            throw ValidationException::withMessages(['deliveryZone' => ['The delivery zone is invalid.']]);
        }

        $prepared = $this->prepareItems($payload['items'] ?? []);

        return DB::transaction(function () use ($payload, $account, $ip, $requestId, $phone, $zone, $prepared) {
            $weightIds = collect($prepared)->pluck('weight.id')->sort()->values()->all();
            $locked = ProductWeight::query()
                ->whereIn('id', $weightIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $subtotal = 0;
            $lines = [];

            foreach ($prepared as $line) {
                $product = $line['product'];
                $weight = $locked->get($line['weight']->id);

                if ($product->status !== ProductStatus::Active || ! $product->in_stock || $product->trashed()) {
                    throw ValidationException::withMessages([
                        'items' => ["{$product->name_bn}: product is unavailable."],
                    ]);
                }

                if ($weight->product_id !== $product->id) {
                    throw ValidationException::withMessages([
                        'items' => ["{$product->name_bn}: invalid weight selection."],
                    ]);
                }

                if ($weight->stock < $line['qty']) {
                    throw ValidationException::withMessages([
                        'items' => ["{$product->name_bn} ({$weight->label}): only {$weight->stock} left."],
                    ]);
                }

                $lineTotal = $weight->price * $line['qty'];
                $subtotal += $lineTotal;
                $lines[] = [$product, $weight, $line['qty'], $lineTotal];
            }

            $settings = $this->settings->fresh();
            $deliveryFee = $subtotal >= $settings->free_shipping_threshold
                ? 0
                : ($zone === DeliveryZone::InsideDhaka ? $settings->delivery_fee_inside : $settings->delivery_fee_outside);

            $discount = 0;
            $total = $subtotal + $deliveryFee - $discount;

            $customer = $this->upsertCustomer($payload['customer'], $phone, $account);

            $days = $zone === DeliveryZone::InsideDhaka
                ? $settings->inside_delivery_days
                : $settings->outside_delivery_days;

            $order = $this->createWithNumber([
                'client_request_id' => $requestId,
                'customer_id' => $customer->id,
                'status' => OrderStatus::Pending,
                'payment_method' => PaymentMethod::from($payload['paymentMethod']),
                'delivery_zone' => $zone,
                'customer_name' => $payload['customer']['name'],
                'customer_phone' => $phone,
                'customer_email' => $payload['customer']['email'] ?? null,
                'district' => $payload['customer']['district'],
                'address' => $payload['customer']['address'],
                'note' => $payload['customer']['note'] ?? null,
                'subtotal' => $subtotal,
                'delivery_fee' => $deliveryFee,
                'discount' => $discount,
                'total' => $total,
                'expected_delivery_date' => now()->addDays($days),
                'placed_by' => $account ? 'customer' : 'guest',
                'ip_address' => $ip,
            ]);

            foreach ($lines as [$product, $weight, $qty, $lineTotal]) {
                $order->items()->create([
                    'product_id' => $product->id,
                    'product_weight_id' => $weight->id,
                    'product_slug' => $product->slug,
                    'product_name_bn' => $product->name_bn,
                    'weight_label' => $weight->label,
                    'grams' => $weight->grams,
                    'unit_price' => $weight->price,
                    'qty' => $qty,
                    'line_total' => $lineTotal,
                    'image_path' => $product->image_path,
                ]);

                $weight->decrement('stock', $qty);
            }

            $order->statusHistory()->create([
                'status' => OrderStatus::Pending->value,
                'note' => 'Order placed by customer',
                'changed_by_name' => 'System',
                'created_at' => now(),
            ]);

            $customer->forceFill(['last_order_at' => now()])->save();

            return $order->load(['items', 'statusHistory']);
        });
    }

    public function transition(Order $order, OrderStatus $next, array $data, User $admin): Order
    {
        return DB::transaction(function () use ($order, $next, $data, $admin) {
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if (! $locked->status->canTransitionTo($next)) {
                abort(409, 'Invalid order status transition.');
            }

            if ($next->restoresStock() && $locked->stock_restored_at === null) {
                foreach ($locked->items()->whereNotNull('product_weight_id')->get() as $item) {
                    ProductWeight::query()
                        ->whereKey($item->product_weight_id)
                        ->lockForUpdate()
                        ->increment('stock', $item->qty);
                }

                $locked->stock_restored_at = now();
            }

            $locked->status = $next;

            if ($next === OrderStatus::Rejected) {
                $locked->rejection_reason = $data['reason'] ?? 'other';
                $locked->rejection_feedback = $data['feedback'] ?? null;
                $locked->rejected_by = $admin->name;
                $locked->rejected_at = now();
            }

            if ($next === OrderStatus::Shipped) {
                $locked->courier_name = $data['courier'] ?? $locked->courier_name;
                $locked->courier_tracking_id = $data['trackingId'] ?? $locked->courier_tracking_id;
            }

            $locked->save();

            $note = $data['note'] ?? null;
            if (! $note && $next === OrderStatus::Rejected) {
                $note = $data['reason'] ?? null;
            }
            if (! $note && $next === OrderStatus::Shipped) {
                $note = 'Handed over to courier';
            }

            $locked->statusHistory()->create([
                'status' => $next->value,
                'note' => $note,
                'changed_by_user_id' => $admin->id,
                'changed_by_name' => $admin->name,
                'created_at' => now(),
            ]);

            $this->audit->log('order.status.updated', Order::class, $locked->id, [
                'status' => $next->value,
            ]);

            return $locked->refresh()->load(['items', 'statusHistory']);
        });
    }

    public static function normalizePhone(?string $phone): string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);

        if (str_starts_with($digits, '8801')) {
            $digits = '0'.substr($digits, 3);
        } elseif (str_starts_with($digits, '1') && strlen($digits) === 10) {
            $digits = '0'.$digits;
        }

        return $digits;
    }

    /**
     * @return array<int, array{product: Product, weight: ProductWeight, qty: int}>
     */
    private function prepareItems(array $items): array
    {
        $prepared = [];

        foreach ($items as $index => $item) {
            $product = null;

            if (! empty($item['productId'])) {
                $product = Product::query()->withTrashed()->find($item['productId']);
            }

            if (! $product && ! empty($item['slug'])) {
                $product = Product::query()->withTrashed()->where('slug', $item['slug'])->first();
            }

            if (! $product) {
                throw ValidationException::withMessages([
                    "items.{$index}" => ['Product not found.'],
                ]);
            }

            $weight = null;
            if (! empty($item['grams'])) {
                $weight = $product->weights()->where('grams', (int) $item['grams'])->first();
            }
            if (! $weight && ! empty($item['weightLabel'])) {
                $weight = $product->weights()->where('label', $item['weightLabel'])->first();
            }

            if (! $weight) {
                throw ValidationException::withMessages([
                    "items.{$index}" => ['Selected weight is unavailable.'],
                ]);
            }

            $prepared[] = [
                'product' => $product,
                'weight' => $weight,
                'qty' => (int) $item['qty'],
            ];
        }

        return $prepared;
    }

    private function upsertCustomer(array $data, string $phone, ?Customer $account): Customer
    {
        $customer = $account ?: Customer::query()->where('phone', $phone)->first();

        if (! $customer) {
            return Customer::query()->create([
                'name' => $data['name'],
                'phone' => $phone,
                'email' => $data['email'] ?? null,
                'district' => $data['district'],
                'address' => $data['address'],
                'last_order_at' => now(),
            ]);
        }

        $customer->name = $data['name'];
        $customer->district = $data['district'];
        $customer->address = $data['address'];

        if (! empty($data['email']) && ! $customer->email) {
            $customer->email = $data['email'];
        }

        $customer->last_order_at = now();
        $customer->save();

        return $customer;
    }

    private function createWithNumber(array $attributes): Order
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                return Order::query()->create([
                    ...$attributes,
                    'order_number' => $this->orderNumbers->next(),
                ]);
            } catch (QueryException $e) {
                if ($attempt === 4 || ! str_contains($e->getMessage(), 'order_number')) {
                    throw $e;
                }
            }
        }

        throw new \RuntimeException('Unable to generate an order number.');
    }
}
