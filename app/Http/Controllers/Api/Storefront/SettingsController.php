<?php

namespace App\Http\Controllers\Api\Storefront;

use App\Http\Controllers\Controller;
use App\Http\Resources\Storefront\SettingsResource;
use App\Services\SettingsService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class SettingsController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly SettingsService $settings) {}

    public function show(): JsonResponse
    {
        return $this->success(new SettingsResource($this->settings->get()));
    }
}
