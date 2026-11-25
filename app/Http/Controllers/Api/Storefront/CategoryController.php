<?php

namespace App\Http\Controllers\Api\Storefront;

use App\Http\Controllers\Controller;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class CategoryController extends Controller
{
    use ApiResponse;

    public function index(): JsonResponse
    {
        $categories = Category::query()
            ->where('hidden', false)
            ->withCount('products')
            ->orderBy('sort_order')
            ->get();

        return $this->success(CategoryResource::collection($categories)->resolve());
    }
}
