<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\StatsService;
use App\Support\Api;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StatsController extends Controller
{
    public function index(Request $request, StatsService $service): JsonResponse
    {
        return Api::ok($service->build($request->user()));
    }
}
