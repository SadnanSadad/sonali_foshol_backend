<?php

use App\Models\CalendarEvent;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\Review;
use App\Models\User;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('public');
    $this->seed(DemoDataSeeder::class);
    $this->admin = User::query()->where('role', 'superadmin')->firstOrFail();
    $this->actingAs($this->admin, 'admin');
});

it('returns dashboard summary, sales, status breakdown and top products', function () {
    $this->getJson('/api/admin/dashboard/summary')
        ->assertOk()
        ->assertJsonStructure(['data' => [
            'todayOrders', 'todayRevenue', 'pendingOrders', 'lowStockCount', 'revenue',
            'orderCount', 'averageOrderValue', 'revenueChangePct', 'orderChangePct',
        ]]);

    $this->getJson('/api/admin/dashboard/sales?range=7d')->assertOk()->assertJsonCount(7, 'data');
    $this->getJson('/api/admin/dashboard/sales?range=90d')->assertOk()->assertJsonCount(90, 'data');
    $this->getJson('/api/admin/dashboard/status-breakdown')->assertOk()->assertJsonCount(7, 'data');
    $this->getJson('/api/admin/dashboard/top-products?limit=3')
        ->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonStructure(['data' => [['productId', 'nameBn', 'slug', 'image', 'unitsSold', 'revenue']]]);
});

it('filters and sorts admin products', function () {
    $this->getJson('/api/admin/products?limit=5')
        ->assertOk()
        ->assertJsonCount(5, 'data')
        ->assertJsonPath('meta.limit', 5)
        ->assertJsonPath('meta.total', 29);

    $this->getJson('/api/admin/products?category=oil&limit=100')
        ->assertOk()
        ->assertJsonPath('meta.total', 7);

    $this->getJson('/api/admin/products?status=draft&limit=100')
        ->assertOk()
        ->assertJsonPath('meta.total', 3);

    $this->getJson('/api/admin/products?stock=out&limit=100')->assertOk();

    $search = $this->getJson('/api/admin/products?search=Honey&limit=100')->assertOk();
    expect($search->json('meta.total'))->toBeGreaterThan(0);
});

it('creates, updates, bulk updates and soft deletes products', function () {
    $payload = [
        'slug' => 'test-rice',
        'nameBn' => 'টেস্ট চাল',
        'nameEn' => 'Test Rice',
        'category' => 'rice',
        'originBn' => 'Dinajpur',
        'image' => '/storage/products/miniket-rice.svg',
        'images' => ['/storage/products/miniket-rice.svg'],
        'badgeBn' => 'নতুন',
        'inStock' => true,
        'status' => 'active',
        'shortDescBn' => 'টেস্ট বিবরণ',
        'fullDescBn' => 'টেস্ট বিস্তারিত বিবরণ',
        'nutritionHighlights' => ['ফাইবার'],
        'nutritionFacts' => [['labelBn' => 'ফাইবার', 'valueBn' => '২ গ্রাম']],
        'cookingTipsBn' => 'ভালোভাবে রান্না করুন',
        'weights' => [
            ['label' => '১ কেজি', 'grams' => 1000, 'price' => 100, 'mrp' => 120, 'stock' => 10, 'sku' => 'TEST-1000'],
        ],
    ];

    $created = $this->postJson('/api/admin/products', $payload)
        ->assertCreated()
        ->assertJsonPath('data.slug', 'test-rice')
        ->assertJsonPath('data.stock', 10);

    $id = $created->json('data.id');

    $this->putJson("/api/admin/products/{$id}", [
        ...$payload,
        'nameEn' => 'Updated Rice',
        'weights' => [
            ['label' => '১ কেজি', 'grams' => 1000, 'price' => 110, 'mrp' => 120, 'stock' => 7, 'sku' => 'TEST-1000'],
        ],
    ])->assertOk()->assertJsonPath('data.nameEn', 'Updated Rice');

    $this->patchJson('/api/admin/products/bulk', [
        'action' => 'unpublish',
        'ids' => [$id],
    ])->assertOk()->assertJsonPath('data.count', 1);

    $this->deleteJson("/api/admin/products/{$id}")->assertOk()->assertJsonPath('data.success', true);
    expect(Product::withTrashed()->find($id)->trashed())->toBeTrue();
});

it('validates product weights and records image uploads', function () {
    $this->postJson('/api/admin/products', [
        'slug' => 'bad-product',
        'nameBn' => 'খারাপ',
        'category' => 'rice',
        'image' => '/storage/products/x.jpg',
        'shortDescBn' => 'Short',
        'fullDescBn' => 'Full',
        'weights' => [
            ['label' => '১ কেজি', 'grams' => 1000, 'price' => 200, 'mrp' => 100, 'stock' => 5],
        ],
    ])->assertStatus(422)->assertJsonValidationErrors('weights.0.mrp');

    $this->postJson('/api/admin/uploads', [
        'file' => UploadedFile::fake()->image('product.jpg', 600, 600),
    ])->assertCreated()->assertJsonStructure(['data' => ['url']]);

    $this->postJson('/api/admin/uploads', [
        'file' => UploadedFile::fake()->create('vector.svg', 10, 'image/svg+xml'),
    ])->assertStatus(422);
});

it('manages categories with product-count protection', function () {
    $this->getJson('/api/admin/categories')->assertOk()->assertJsonCount(4, 'data');

    $rice = Category::query()->where('slug', 'rice')->firstOrFail();
    $this->deleteJson("/api/admin/categories/{$rice->id}")->assertStatus(409);

    $created = $this->postJson('/api/admin/categories', [
        'slug' => 'dry-fruits',
        'nameEn' => 'Dry Fruits',
        'nameBn' => 'শুকনো ফল',
        'order' => 9,
        'hidden' => false,
    ])->assertCreated();

    $this->putJson("/api/admin/categories/{$created->json('data.id')}", [
        'nameEn' => 'Nuts',
    ])->assertOk()->assertJsonPath('data.nameEn', 'Nuts');

    $this->deleteJson("/api/admin/categories/{$created->json('data.id')}")->assertOk();
});

it('filters admin orders and updates status', function () {
    $this->getJson('/api/admin/orders?status=pending&limit=100')
        ->assertOk()
        ->assertJsonPath('meta.total', 5);

    $this->getJson('/api/admin/orders?search=SFO-2026-8492')->assertOk()->assertJsonPath('meta.total', 1);
    $this->getJson('/api/admin/orders?zone=outside_dhaka&limit=100')->assertOk();
    $this->getJson('/api/admin/orders?payment=whatsapp&limit=100')->assertOk();
    $this->getJson('/api/admin/orders?from=2026-11-01&to=2026-11-30&limit=100')->assertOk();

    $order = Order::query()->where('status', 'confirmed')->firstOrFail();
    $this->getJson("/api/admin/orders/{$order->order_number}")->assertOk()->assertJsonPath('data.id', $order->id);
});

it('computes customer aggregates, searches and flags customers', function () {
    $this->getJson('/api/admin/customers?limit=100')
        ->assertOk()
        ->assertJsonPath('meta.total', 10);

    $customer = Customer::query()->where('phone', '01711234567')->firstOrFail();

    $this->getJson('/api/admin/customers?search=01711234567')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.orderCount', 2);

    $this->getJson("/api/admin/customers/{$customer->id}/orders")->assertOk()->assertJsonCount(2, 'data');

    $this->patchJson("/api/admin/customers/{$customer->id}/flag", ['flagged' => true])
        ->assertOk()
        ->assertJsonPath('data.flagged', true);

    $this->getJson('/api/admin/customers?flagged=yes')->assertOk()->assertJsonPath('meta.total', 3);
});

it('moderates reviews', function () {
    $this->getJson('/api/admin/reviews?status=approved&limit=100')->assertOk()->assertJsonPath('meta.total', 12);

    $review = Review::query()->firstOrFail();

    $this->patchJson("/api/admin/reviews/{$review->id}", ['status' => 'hidden'])
        ->assertOk()
        ->assertJsonPath('data.status', 'hidden');

    $this->deleteJson("/api/admin/reviews/{$review->id}")->assertOk();
    expect(Review::query()->find($review->id))->toBeNull();
});

it('returns deliveries and manages admin calendar events', function () {
    $from = now()->subDays(30)->toDateString();
    $to = now()->addDays(30)->toDateString();

    $response = $this->getJson("/api/admin/calendar/events?from={$from}&to={$to}")->assertOk();
    expect(collect($response->json('data'))->pluck('type'))->toContain('delivery');

    $event = $this->postJson('/api/admin/calendar/events', [
        'title' => 'Team meeting',
        'start' => now()->addDay()->toIso8601String(),
        'allDay' => false,
        'note' => 'Plan the week',
    ])->assertCreated()->assertJsonPath('data.type', 'admin');

    $this->putJson("/api/admin/calendar/events/{$event->json('data.id')}", [
        'title' => 'Updated meeting',
    ])->assertOk()->assertJsonPath('data.title', 'Updated meeting');

    $this->deleteJson("/api/admin/calendar/events/{$event->json('data.id')}")->assertOk();

    $order = Order::query()->whereNotNull('expected_delivery_date')->firstOrFail();
    $this->putJson("/api/admin/calendar/events/delivery-{$order->id}", [
        'start' => now()->addDays(3)->toIso8601String(),
    ])->assertOk()->assertJsonPath('data.orderId', $order->id);
});

it('reads and updates store settings with validation', function () {
    $this->getJson('/api/admin/settings')
        ->assertOk()
        ->assertJsonPath('data.storeName', 'Sonali Foshol Organics');

    $this->putJson('/api/admin/settings', [
        'storeName' => 'Updated Store',
        'whatsappNumber' => '01712345678',
        'deliveryFeeInside' => 70,
        'freeShippingThreshold' => 2500,
    ])->assertOk()
        ->assertJsonPath('data.storeName', 'Updated Store')
        ->assertJsonPath('data.deliveryFeeInside', 70);

    $this->putJson('/api/admin/settings', [
        'whatsappNumber' => 'abc',
    ])->assertStatus(422);

    $this->putJson('/api/admin/settings', [
        'facebookUrl' => 'http://insecure.example.com',
    ])->assertStatus(422);
});
