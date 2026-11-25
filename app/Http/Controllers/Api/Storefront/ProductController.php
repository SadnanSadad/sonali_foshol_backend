<?php

namespace App\Http\Controllers\Api\Storefront;

use App\Enums\ProductStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Storefront\ProductResource;
use App\Models\Product;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $query = Product::query()
            ->where('status', ProductStatus::Active->value)
            ->with(['category', 'images', 'weights'])
            ->withMin('weights', 'price');

        if ($category = $request->query('category')) {
            if ($category !== 'all') {
                $query->whereHas('category', fn ($q) => $q->where('slug', $category));
            }
        }

        if ($search = trim((string) $request->query('search'))) {
            $query->where(function ($q) use ($search) {
                $q->where('name_bn', 'like', "%{$search}%")
                    ->orWhere('name_en', 'like', "%{$search}%")
                    ->orWhere('origin_bn', 'like', "%{$search}%")
                    ->orWhere('short_desc_bn', 'like', "%{$search}%");
            });
        }

        match ($request->query('sort', 'default')) {
            'price-asc' => $query->orderBy('weights_min_price'),
            'price-desc' => $query->orderByDesc('weights_min_price'),
            default => $query->orderBy('created_at')->orderBy('id'),
        };

        $products = $query->get();

        return $this->success(ProductResource::collection($products)->resolve());
    }

    public function show(string $slug): JsonResponse
    {
        $product = Product::query()
            ->where('slug', $slug)
            ->where('status', ProductStatus::Active->value)
            ->with(['category', 'images', 'weights'])
            ->firstOrFail();

        return $this->success(new ProductResource($product));
    }

    public function related(Request $request, string $slug): JsonResponse
    {
        $limit = min(12, max(1, (int) $request->query('limit', 4)));

        $product = Product::query()
            ->where('slug', $slug)
            ->where('status', ProductStatus::Active->value)
            ->with('category')
            ->firstOrFail();

        $products = Product::query()
            ->where('status', ProductStatus::Active->value)
            ->where('id', '!=', $product->id)
            ->when($product->category_id, fn ($q) => $q->where('category_id', $product->category_id))
            ->with(['category', 'images', 'weights'])
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        return $this->success(ProductResource::collection($products)->resolve());
    }
}
