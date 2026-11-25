<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;

trait ApiResponse
{
    protected function success(mixed $data = null, int $status = 200, ?array $meta = null): JsonResponse
    {
        $payload = ['success' => true, 'data' => $data];

        if ($meta !== null) {
            $payload['meta'] = $meta;
        }

        return response()->json($payload, $status);
    }

    protected function paginated(LengthAwarePaginator $paginator, mixed $data): JsonResponse
    {
        return $this->success($data, 200, [
            'page' => $paginator->currentPage(),
            'limit' => $paginator->perPage(),
            'total' => $paginator->total(),
            'totalPages' => $paginator->lastPage(),
        ]);
    }

    protected function error(string $message, int $status = 400, ?array $errors = null): JsonResponse
    {
        $payload = ['success' => false, 'message' => $message];

        if ($errors !== null) {
            $payload['errors'] = $errors;
        }

        return response()->json($payload, $status);
    }
}
