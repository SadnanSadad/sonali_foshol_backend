<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\ProductStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\ProductResource;
use App\Models\Category;
use App\Models\Product;
use App\Services\AuditService;
use App\Services\SettingsService;
use App\Support\ApiResponse;
use App\Support\Media;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProductController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly SettingsService $settings,
        private readonly AuditService $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $limit = min(100, max(1, (int) $request->query('limit', 10)));
        $threshold = $this->settings->get()->low_stock_threshold;

        $query = Product::query()
            ->with(['category', 'images', 'weights'])
            ->withSum('weights', 'stock')
            ->withMin('weights', 'price');

        if ($category = $request->query('category')) {
            if ($category !== 'all') {
                $query->whereHas('category', fn ($q) => $q->where('slug', $category));
            }
        }

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name_bn', 'like', "%{$search}%")
                    ->orWhere('name_en', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%")
                    ->orWhere('origin_bn', 'like', "%{$search}%");
            });
        }

        $stock = $request->query('stock', 'all');
        $query->when($stock === 'in', fn ($q) => $q->havingRaw('COALESCE(weights_sum_stock, 0) > ?', [$threshold]))
            ->when($stock === 'low', fn ($q) => $q->havingRaw('COALESCE(weights_sum_stock, 0) > 0 AND COALESCE(weights_sum_stock, 0) <= ?', [$threshold]))
            ->when($stock === 'out', fn ($q) => $q->havingRaw('COALESCE(weights_sum_stock, 0) = 0'));

        $status = $request->query('status', 'all');
        if (in_array($status, ['active', 'draft'], true)) {
            $query->where('status', $status);
        }

        match ($request->query('sort', 'newest')) {
            'name' => $query->orderBy('name_bn'),
            'price' => $query->orderBy('weights_min_price'),
            'stock' => $query->orderByDesc('weights_sum_stock'),
            default => $query->orderByDesc('created_at'),
        };

        $paginator = $query->paginate($limit);

        return $this->paginated($paginator, ProductResource::collection($paginator->getCollection())->resolve());
    }

    public function show(string $id): JsonResponse
    {
        $product = Product::query()
            ->with(['category', 'images', 'weights'])
            ->where('id', $id)
            ->orWhere('slug', $id)
            ->firstOrFail();

        return $this->success(new ProductResource($product));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        $product = DB::transaction(function () use ($data) {
            $category = Category::query()->where('slug', $data['category'])->firstOrFail();

            $product = Product::query()->create([
                'category_id' => $category->id,
                'slug' => $data['slug'],
                'name_bn' => $data['nameBn'],
                'name_en' => $data['nameEn'] ?? null,
                'origin_bn' => $data['originBn'] ?? null,
                'short_desc_bn' => $data['shortDescBn'],
                'full_desc_bn' => $data['fullDescBn'],
                'image_path' => Media::path($data['image']),
                'badge_bn' => $data['badgeBn'] ?? null,
                'in_stock' => (bool) ($data['inStock'] ?? true),
                'status' => $data['status'] ?? ProductStatus::Active->value,
                'nutrition_highlights' => $data['nutritionHighlights'] ?? [],
                'nutrition_facts' => $data['nutritionFacts'] ?? [],
                'cooking_tips_bn' => $data['cookingTipsBn'] ?? null,
            ]);

            $this->syncWeights($product, $data['weights']);
            $this->syncImages($product, $data['images'] ?? []);

            return $product;
        });

        $this->audit->log('product.created', Product::class, $product->id, ['slug' => $product->slug], $request);

        return $this->success(new ProductResource($product->load(['category', 'images', 'weights'])), 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $product = Product::query()->where('id', $id)->orWhere('slug', $id)->firstOrFail();
        $data = $this->validated($request, $product);

        DB::transaction(function () use ($product, $data) {
            if (isset($data['category'])) {
                $product->category_id = Category::query()->where('slug', $data['category'])->firstOrFail()->id;
            }

            $map = [
                'slug' => 'slug', 'nameBn' => 'name_bn', 'nameEn' => 'name_en', 'originBn' => 'origin_bn',
                'shortDescBn' => 'short_desc_bn', 'fullDescBn' => 'full_desc_bn', 'badgeBn' => 'badge_bn',
                'inStock' => 'in_stock', 'status' => 'status', 'nutritionHighlights' => 'nutrition_highlights',
                'nutritionFacts' => 'nutrition_facts', 'cookingTipsBn' => 'cooking_tips_bn',
            ];

            foreach ($map as $api => $column) {
                if (array_key_exists($api, $data)) {
                    $product->{$column} = $data[$api];
                }
            }

            if (array_key_exists('image', $data)) {
                $product->image_path = Media::path($data['image']);
            }

            $product->save();

            if (array_key_exists('weights', $data)) {
                $product->weights()->delete();
                $this->syncWeights($product, $data['weights']);
            }

            if (array_key_exists('images', $data)) {
                $product->images()->delete();
                $this->syncImages($product, $data['images']);
            }
        });

        $this->audit->log('product.updated', Product::class, $product->id, ['slug' => $product->slug], $request);

        return $this->success(new ProductResource($product->refresh()->load(['category', 'images', 'weights'])));
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $product = Product::query()->where('id', $id)->orWhere('slug', $id)->firstOrFail();
        $product->delete();

        $this->audit->log('product.deleted', Product::class, $product->id, ['slug' => $product->slug], $request);

        return $this->success(['success' => true]);
    }

    public function bulk(Request $request): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['publish', 'unpublish', 'delete'])],
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['string'],
        ]);

        $products = Product::query()->whereIn('id', $data['ids'])->get();

        foreach ($products as $product) {
            match ($data['action']) {
                'publish' => $product->forceFill(['status' => ProductStatus::Active->value])->save(),
                'unpublish' => $product->forceFill(['status' => ProductStatus::Draft->value])->save(),
                'delete' => $product->delete(),
            };
        }

        $this->audit->log('product.bulk', Product::class, null, [
            'action' => $data['action'],
            'ids' => $data['ids'],
        ], $request);

        return $this->success(['count' => $products->count()]);
    }

    private function validated(Request $request, ?Product $product = null): array
    {
        return $request->validate([
            'slug' => ['required', 'string', 'max:160', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('products', 'slug')->ignore($product?->id)],
            'nameBn' => ['required', 'string', 'max:200'],
            'nameEn' => ['nullable', 'string', 'max:200'],
            'category' => ['required', 'string', 'exists:categories,slug'],
            'originBn' => ['nullable', 'string', 'max:200'],
            'image' => ['required', 'string', 'max:500'],
            'images' => ['sometimes', 'array'],
            'images.*' => ['string', 'max:500'],
            'badgeBn' => ['nullable', 'string', 'max:120'],
            'inStock' => ['sometimes', 'boolean'],
            'status' => ['sometimes', Rule::in(['active', 'draft'])],
            'shortDescBn' => ['required', 'string'],
            'fullDescBn' => ['required', 'string'],
            'nutritionHighlights' => ['sometimes', 'array'],
            'nutritionHighlights.*' => ['string'],
            'nutritionFacts' => ['sometimes', 'array'],
            'nutritionFacts.*.labelBn' => ['required_with:nutritionFacts', 'string'],
            'nutritionFacts.*.valueBn' => ['required_with:nutritionFacts', 'string'],
            'cookingTipsBn' => ['nullable', 'string'],
            'weights' => ['required', 'array', 'min:1'],
            'weights.*.label' => ['required', 'string', 'max:80'],
            'weights.*.grams' => ['required', 'integer', 'min:1'],
            'weights.*.price' => ['required', 'integer', 'min:1'],
            'weights.*.mrp' => ['required', 'integer', 'min:1', 'gte:weights.*.price'],
            'weights.*.stock' => ['required', 'integer', 'min:0'],
            'weights.*.sku' => ['nullable', 'string', 'max:80', Rule::unique('product_weights', 'sku')->ignore($product?->id, 'product_id')],
        ], [
            'weights.*.mrp.gte' => 'The MRP must be greater than or equal to the price.',
        ]);
    }

    private function syncWeights(Product $product, array $weights): void
    {
        $grams = [];

        foreach ($weights as $index => $weight) {
            if (isset($grams[$weight['grams']])) {
                throw ValidationException::withMessages(['weights' => ['Each weight must be unique per product.']]);
            }
            $grams[$weight['grams']] = true;

            $product->weights()->create([
                'label' => $weight['label'],
                'grams' => $weight['grams'],
                'price' => $weight['price'],
                'mrp' => $weight['mrp'],
                'stock' => $weight['stock'],
                'sku' => $weight['sku'] ?? null,
                'sort_order' => $index,
            ]);
        }
    }

    private function syncImages(Product $product, array $images): void
    {
        foreach (array_values($images) as $index => $path) {
            $product->images()->create([
                'path' => Media::path($path),
                'sort_order' => $index,
            ]);
        }
    }
}
