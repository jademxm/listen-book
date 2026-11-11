<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\AdminStatsService;
use App\Support\Api;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** 运营数据面板只读聚合。GET 不落操作日志。 */
class StatsController extends Controller
{
    public function index(Request $request, AdminStatsService $service): JsonResponse
    {
        return Api::ok($service->build((int) $request->input('days', 30)));
    }
}
