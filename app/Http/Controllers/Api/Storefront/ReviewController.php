<?php

namespace App\Http\Controllers\Api\Storefront;

use App\Enums\ReviewStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Storefront\ReviewResource;
use App\Models\Review;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReviewController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $limit = min(50, max(1, (int) $request->query('limit', 20)));

        $reviews = Review::query()
            ->where('status', ReviewStatus::Approved->value)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        return $this->success(ReviewResource::collection($reviews)->resolve());
    }
}
