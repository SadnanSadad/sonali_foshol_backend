<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\Review;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DemoDataSeeder::class);
});

it('returns public store settings in storefront shape', function () {
    $this->getJson('/api/storefront/settings')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonStructure([
            'success',
            'data' => [
                'brandNameBn', 'brandTaglineBn', 'whatsappNumber', 'facebookPageUrl',
                'messengerUrl', 'insideDhakaDelivery', 'outsideDhakaDelivery',
                'freeShippingThreshold', 'phoneDisplay', 'email', 'addressBn',
            ],
        ]);
});

it('returns visible categories without the all pseudo-category', function () {
    $response = $this->getJson('/api/storefront/categories')->assertOk();

    $slugs = collect($response->json('data'))->pluck('slug');

    expect($slugs)->toContain('rice', 'oil', 'honey-ghee', 'spices')
        ->not->toContain('all');
});

it('filters products by category, search and price sort', function () {
    $all = $this->getJson('/api/storefront/products')->assertOk()->json('data');
    expect(count($all))->toBe(26);

    $rice = $this->getJson('/api/storefront/products?category=rice')->assertOk()->json('data');
    expect(collect($rice)->every(fn ($product) => $product['category'] === 'rice'))->toBeTrue();

    $search = $this->getJson('/api/storefront/products?search=Miniket')->assertOk()->json('data');
    expect($search)->not->toBeEmpty()
        ->and(collect($search)->contains(fn ($product) => str_contains($product['slug'], 'miniket')))->toBeTrue();

    $asc = $this->getJson('/api/storefront/products?sort=price-asc')->assertOk()->json('data');
    $prices = array_map(fn ($product) => min(array_column($product['weights'], 'price')), $asc);
    $sorted = $prices;
    sort($sorted);
    expect($prices)->toBe($sorted);
});

it('only exposes active products publicly', function () {
    $this->getJson('/api/storefront/products/sesame-oil')->assertNotFound();

    $product = Product::query()->where('slug', 'miniket-rice')->firstOrFail();
    $this->getJson('/api/storefront/products/miniket-rice')
        ->assertOk()
        ->assertJsonPath('data.slug', $product->slug)
        ->assertJsonPath('data.category', 'rice')
        ->assertJsonMissingPath('data.stock');
});

it('returns related products from the same category', function () {
    $this->getJson('/api/storefront/products/miniket-rice/related?limit=4')
        ->assertOk()
        ->assertJsonCount(4, 'data')
        ->assertJsonMissing(['slug' => 'miniket-rice']);
});

it('returns only approved storefront reviews', function () {
    Review::query()->updateOrCreate(
        ['author' => 'Hidden Reviewer', 'text' => 'Hidden review text', 'rating' => 1],
        ['status' => 'hidden', 'product_name_bn' => null, 'location' => null],
    );

    $reviews = $this->getJson('/api/storefront/reviews')->assertOk()->json('data');

    expect(collect($reviews)->pluck('customerName'))->not->toContain('Hidden Reviewer')
        ->and(collect($reviews)->every(fn ($review) => isset($review['commentBn'])))->toBeTrue();
});

it('hides products when all stock is exhausted', function () {
    $product = Product::query()->where('slug', 'miniket-rice')->firstOrFail();
    $product->weights()->update(['stock' => 0]);
    $product->forceFill(['in_stock' => true])->save();

    $this->getJson('/api/storefront/products/miniket-rice')
        ->assertOk()
        ->assertJsonPath('data.inStock', false);
});
