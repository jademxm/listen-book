<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReorderRequest;
use App\Services\CatalogService;
use App\Services\ReorderService;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;

class ReorderController extends Controller
{
    public function tracks(ReorderRequest $request, ReorderService $service): JsonResponse
    {
        $trackId = (int) $request->input('track_id');
        if ($trackId <= 0) {
            return response()->json(['ok' => false, 'message' => '缺少 track_id'], 422);
        }

        try {
            $service->tracks((int) $request->input('album_id'), $trackId, $request->targetId());
        } catch (InvalidArgumentException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }

        CatalogService::bump();

        return response()->json(['ok' => true, 'catalog_version' => CatalogService::version()]);
    }

    public function albums(ReorderRequest $request, ReorderService $service): JsonResponse
    {
        try {
            $service->albums((int) $request->input('album_id'), $request->targetId());
        } catch (InvalidArgumentException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }

        CatalogService::bump();

        return response()->json(['ok' => true, 'catalog_version' => CatalogService::version()]);
    }
}
