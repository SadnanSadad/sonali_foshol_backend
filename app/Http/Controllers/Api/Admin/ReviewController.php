<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\ReviewStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\ReviewResource;
use App\Models\Review;
use App\Services\AuditService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ReviewController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly AuditService $audit) {}

    public function index(Request $request): JsonResponse
    {
        $limit = min(100, max(1, (int) $request->query('limit', 10)));

        $query = Review::query();

        $status = $request->query('status', 'all');
        if ($status !== 'all' && in_array($status, ['pending', 'approved', 'hidden'], true)) {
            $query->where('status', $status);
        }

        if ($search = trim((string) $request->query('search'))) {
            $query->where(function ($q) use ($search) {
                $q->where('author', 'like', "%{$search}%")
                    ->orWhere('text', 'like', "%{$search}%")
                    ->orWhere('product_name_bn', 'like', "%{$search}%");
            });
        }

        if ($rating = $request->query('rating')) {
            $query->where('rating', (int) $rating);
        }

        $paginator = $query->orderByDesc('created_at')->paginate($limit);

        return $this->paginated($paginator, ReviewResource::collection($paginator->getCollection())->resolve());
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::enum(ReviewStatus::class)],
        ]);

        $review = Review::query()->findOrFail($id);
        $review->forceFill(['status' => ReviewStatus::from($data['status'])])->save();

        $this->audit->log('review.status.updated', Review::class, $review->id, ['status' => $data['status']], $request);

        return $this->success(new ReviewResource($review));
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $review = Review::query()->findOrFail($id);
        $review->delete();

        $this->audit->log('review.deleted', Review::class, $id, [], $request);

        return $this->success(['success' => true]);
    }
}
