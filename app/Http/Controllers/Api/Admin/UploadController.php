<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\AuditService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class UploadController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly AuditService $audit) {}

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        $file = $request->file('file');
        $name = (string) Str::uuid().'.'.strtolower($file->getClientOriginalExtension() ?: $file->guessExtension());
        $path = $file->storeAs('products', $name, 'public');

        $this->audit->log('upload.created', null, null, ['path' => $path], $request);

        return $this->success(['url' => Storage::disk('public')->url($path)], 201);
    }
}
