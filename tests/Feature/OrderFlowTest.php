<?php

use App\Models\Order;
use App\Models\Product;
use App\Models\ProductWeight;
use App\Models\User;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DemoDataSeeder::class);
    $this->product = Product::query()->where('slug', 'miniket-rice')->firstOrFail();
    $this->weight = $this->product->weights->firstWhere('grams', 1000);
});

function orderPayload(Product $product, ProductWeight $weight, array $overrides = []): array
{
    return array_replace_recursive([
        'clientRequestId' => (string) Str::uuid(),
        'customer' => [
            'name' => 'Test Customer',
            'phone' => '01912345678',
            'email' => 'test@example.com',
            'district' => 'Dhaka',
            'address' => 'House 12, Road 4, Dhanmondi, Dhaka',
            'note' => 'Please call before delivery.',
        ],
        'items' => [
            ['productId' => $product->id, 'grams' => $weight->grams, 'qty' => 2],
        ],
        'deliveryZone' => 'inside-dhaka',
        'paymentMethod' => 'cod',
        'subtotal' => 1,
        'deliveryFee' => 0,
        'total' => 1,
    ], $overrides);
}

it('creates an order with server-calculated totals and reserved stock', function () {
    $before = $this->weight->fresh()->stock;

    $response = $this->postJson('/api/storefront/orders', orderPayload($this->product, $this->weight))
        ->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.deliveryZone', 'inside-dhaka')
        ->assertJsonPath('data.subtotal', $this->weight->price * 2)
        ->assertJsonPath('data.deliveryFee', 60)
        ->assertJsonPath('data.total', $this->weight->price * 2 + 60);

    expect($response->json('data.orderNumber'))->toMatch('/^SFO-\d{4}-\d{4}$/')
        ->and($this->weight->fresh()->stock)->toBe($before - 2)
        ->and(Order::query()->count())->toBe(21);
});

it('is idempotent for a repeated client request id', function () {
    $payload = orderPayload($this->product, $this->weight);
    $before = $this->weight->fresh()->stock;

    $first = $this->postJson('/api/storefront/orders', $payload)->assertCreated();
    $second = $this->postJson('/api/storefront/orders', $payload)->assertOk();

    expect($second->json('data.orderNumber'))->toBe($first->json('data.orderNumber'))
        ->and($this->weight->fresh()->stock)->toBe($before - 2)
        ->and(Order::query()->count())->toBe(21);
});

it('rejects an order when stock is insufficient', function () {
    $this->weight->forceFill(['stock' => 1])->save();

    $this->postJson('/api/storefront/orders', orderPayload($this->product, $this->weight, [
        'items' => [['productId' => $this->product->id, 'grams' => 1000, 'qty' => 5]],
    ]))->assertStatus(422)->assertJsonValidationErrors('items');
});

it('validates phone, zone and product availability', function () {
    $this->postJson('/api/storefront/orders', orderPayload($this->product, $this->weight, [
        'customer' => ['phone' => '12345'],
    ]))->assertStatus(422)->assertJsonValidationErrors('customer.phone');

    $this->postJson('/api/storefront/orders', orderPayload($this->product, $this->weight, [
        'deliveryZone' => 'moon',
    ]))->assertStatus(422)->assertJsonValidationErrors('deliveryZone');

    $this->postJson('/api/storefront/orders', orderPayload($this->product, $this->weight, [
        'paymentMethod' => 'card',
    ]))->assertStatus(422)->assertJsonValidationErrors('paymentMethod');
});

it('looks up an order only with the matching phone', function () {
    $payload = orderPayload($this->product, $this->weight);
    $orderNumber = $this->postJson('/api/storefront/orders', $payload)->json('data.orderNumber');

    $this->postJson('/api/storefront/orders/lookup', [
        'orderNumber' => $orderNumber,
        'phone' => '01711234567',
    ])->assertNotFound()->assertJsonPath('success', false);

    $this->postJson('/api/storefront/orders/lookup', [
        'orderNumber' => $orderNumber,
        'phone' => '01912345678',
    ])->assertOk()
        ->assertJsonPath('data.orderNumber', $orderNumber)
        ->assertJsonStructure(['data' => ['status', 'items', 'statusHistory']]);
});

it('restores stock exactly once when an order is rejected', function () {
    $admin = User::query()->where('role', 'superadmin')->firstOrFail();
    $this->actingAs($admin, 'admin');

    $order = Order::query()->where('status', 'pending')->with('items')->firstOrFail();
    $before = $order->items->mapWithKeys(fn ($item) => [
        $item->product_weight_id => ProductWeight::query()->findOrFail($item->product_weight_id)->stock,
    ]);

    $this->patchJson("/api/admin/orders/{$order->id}/status", ['status' => 'rejected'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('reason');

    $this->patchJson("/api/admin/orders/{$order->id}/status", [
        'status' => 'rejected',
        'reason' => 'fake_order',
        'feedback' => 'Duplicate order.',
    ])->assertOk()->assertJsonPath('data.status', 'rejected');

    foreach ($order->items as $item) {
        expect(ProductWeight::query()->findOrFail($item->product_weight_id)->stock)
            ->toBe($before[$item->product_weight_id] + $item->qty);
    }

    expect($order->fresh()->stock_restored_at)->not->toBeNull();

    $this->patchJson("/api/admin/orders/{$order->id}/status", [
        'status' => 'cancelled',
    ])->assertStatus(409);
});

it('rejects invalid transitions and records courier details', function () {
    $admin = User::query()->where('role', 'superadmin')->firstOrFail();
    $this->actingAs($admin, 'admin');

    $order = Order::query()->where('status', 'pending')->firstOrFail();

    $this->patchJson("/api/admin/orders/{$order->id}/status", ['status' => 'delivered'])
        ->assertStatus(409);

    $this->patchJson("/api/admin/orders/{$order->id}/status", ['status' => 'confirmed'])->assertOk();
    $this->patchJson("/api/admin/orders/{$order->id}/status", ['status' => 'processing'])->assertOk();
    $this->patchJson("/api/admin/orders/{$order->id}/status", [
        'status' => 'shipped',
        'courier' => 'Pathao',
        'trackingId' => 'PT123456',
    ])->assertOk()
        ->assertJsonPath('data.courier.name', 'Pathao')
        ->assertJsonPath('data.courier.trackingId', 'PT123456')
        ->assertJsonCount(4, 'data.statusHistory');
});

it('lets authenticated customers read only their own orders', function () {
    $payload = orderPayload($this->product, $this->weight);
    $customer = \App\Models\Customer::query()->create([
        'name' => 'Account Customer',
        'phone' => '01912345678',
        'email' => 'account@example.com',
        'password' => 'password123',
        'district' => 'Dhaka',
        'address' => 'Dhanmondi, Dhaka',
    ]);

    $this->actingAs($customer, 'customer');
    $created = $this->postJson('/api/storefront/orders', $payload)->assertCreated();

    $this->getJson('/api/storefront/customer/orders')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.orderNumber', $created->json('data.orderNumber'));

    $foreign = Order::query()->where('customer_id', '!=', $customer->id)->firstOrFail();
    $this->getJson("/api/storefront/customer/orders/{$foreign->order_number}")->assertNotFound();
});
