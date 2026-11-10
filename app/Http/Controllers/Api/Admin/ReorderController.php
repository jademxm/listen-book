<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReorderRequest;
use App\Services\CatalogService;
use App\Services\ReorderService;
use App\Support\Api;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;

class ReorderController extends Controller
{
    public function tracks(ReorderRequest $request, ReorderService $service): JsonResponse
    {
        $trackId = (int) $request->input('track_id');
        if ($trackId <= 0) {
            return Api::fail(42212, '缺少 track_id', null, 422);
        }

        try {
            $degraded = $service->tracks((int) $request->input('album_id'), $trackId, $request->targetId());
        } catch (InvalidArgumentException $e) {
            return Api::fail(42212, $e->getMessage(), null, 422);
        }

        CatalogService::bump();

        return $this->ok($degraded);
    }

    public function albums(ReorderRequest $request, ReorderService $service): JsonResponse
    {
        try {
            $degraded = $service->albums((int) $request->input('album_id'), $request->targetId());
        } catch (InvalidArgumentException $e) {
            return Api::fail(42212, $e->getMessage(), null, 422);
        }

        CatalogService::bump();

        return $this->ok($degraded);
    }

    /** degraded 表示目标行已失效、已退化为移到分组末尾，前端据此提示刷新 */
    private function ok(bool $degraded): JsonResponse
    {
        $data = ['catalog_version' => CatalogService::version()];

        if ($degraded) {
            $data['degraded'] = true;

            return Api::ok($data, '目标行已失效（页面数据可能已过期），已移到该分组末尾，请刷新页面核对顺序。');
        }

        return Api::ok($data);
    }
}
