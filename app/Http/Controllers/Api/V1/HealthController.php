<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

final class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return ApiResponse::success([
            'status' => 'ok',
            'application' => config('app.name'),
            'version' => config('app.api_version'),
            'timestamp' => now()->toIso8601String(),
        ], 'CCSync API is healthy.');
    }
}
