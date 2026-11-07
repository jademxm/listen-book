<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReportHistoryRequest;
use App\Http\Resources\HistoryResource;
use App\Models\PlayHistory;
use App\Services\HistoryService;
use App\Support\Api;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HistoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->query('per_page', 20), 1), 50);

        $rows = PlayHistory::query()
            ->where('wx_user_id', $request->user()->id)
            ->whereHas('track', fn ($q) => $q->where('status', 1))
            ->with([
                'track' => fn ($q) => $q->select('id', 'album_id', 'title', 'cover_url', 'object_key', 'duration_sec', 'status')
                    ->with('album:id,title'),
            ])
            ->orderByDesc('last_played_at')
            ->paginate($perPage);

        return Api::ok([
            'list' => HistoryResource::collection($rows->items()),
            'page' => $rows->currentPage(),
            'per_page' => $rows->perPage(),
            'total' => $rows->total(),
            'has_more' => $rows->hasMorePages(),
        ]);
    }

    public function store(ReportHistoryRequest $request, HistoryService $service): JsonResponse
    {
        try {
            $history = $service->report($request->user(), $request->validated());
        } catch (ModelNotFoundException $e) {
            return Api::fail(4040, $e->getMessage(), null, 404);
        }

        return Api::ok([
            'progress_sec' => $history->progress_sec,
            'finished' => (bool) $history->finished,
        ]);
    }

    public function destroy(Request $request, int $trackId): JsonResponse
    {
        $deleted = PlayHistory::query()
            ->where('wx_user_id', $request->user()->id)
            ->where('track_id', $trackId)
            ->delete();

        return Api::ok(['deleted' => $deleted]);
    }

    public function clear(Request $request, HistoryService $service): JsonResponse
    {
        return Api::ok(['deleted' => $service->clear($request->user())]);
    }
}
