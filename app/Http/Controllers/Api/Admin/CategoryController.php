<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use App\Services\AuditService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CategoryController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly AuditService $audit) {}

    public function index(): JsonResponse
    {
        $categories = Category::query()->withCount('products')->orderBy('sort_order')->get();

        return $this->success(CategoryResource::collection($categories)->resolve());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'slug' => ['required', 'string', 'max:120', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('categories', 'slug')],
            'nameEn' => ['required', 'string', 'max:120'],
            'nameBn' => ['required', 'string', 'max:120'],
            'order' => ['nullable', 'integer', 'min:0'],
            'hidden' => ['sometimes', 'boolean'],
        ]);

        $category = Category::query()->create([
            'slug' => $data['slug'],
            'name_en' => $data['nameEn'],
            'name_bn' => $data['nameBn'],
            'sort_order' => $data['order'] ?? ((int) Category::query()->max('sort_order') + 1),
            'hidden' => (bool) ($data['hidden'] ?? false),
        ]);

        $this->audit->log('category.created', Category::class, $category->id, ['slug' => $category->slug], $request);

        return $this->success(new CategoryResource($category), 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $category = Category::query()->findOrFail($id);

        $data = $request->validate([
            'slug' => ['sometimes', 'string', 'max:120', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('categories', 'slug')->ignore($category->id)],
            'nameEn' => ['sometimes', 'string', 'max:120'],
            'nameBn' => ['sometimes', 'string', 'max:120'],
            'order' => ['sometimes', 'integer', 'min:0'],
            'hidden' => ['sometimes', 'boolean'],
        ]);

        $category->fill([
            'slug' => $data['slug'] ?? $category->slug,
            'name_en' => $data['nameEn'] ?? $category->name_en,
            'name_bn' => $data['nameBn'] ?? $category->name_bn,
            'sort_order' => $data['order'] ?? $category->sort_order,
            'hidden' => $data['hidden'] ?? $category->hidden,
        ])->save();

        $this->audit->log('category.updated', Category::class, $category->id, ['slug' => $category->slug], $request);

        return $this->success(new CategoryResource($category->loadCount('products')));
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $category = Category::query()->findOrFail($id);

        if ($category->products()->exists()) {
            return $this->error('Category cannot be deleted while products exist.', 409);
        }

        $category->delete();

        $this->audit->log('category.deleted', Category::class, $id, [], $request);

        return $this->success(['success' => true]);
    }
}
