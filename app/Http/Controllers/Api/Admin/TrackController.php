<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTrackRequest;
use App\Http\Requests\UpdateTrackRequest;
use App\Models\Album;
use App\Models\Track;
use App\Services\AudioStorageService;
use App\Services\CatalogService;
use App\Support\Api;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use RuntimeException;

class TrackController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = (int) $request->input('per_page', 30);
        $perPage = $perPage < 1 ? 30 : min($perPage, 100);

        $paginator = Track::query()
            ->with('album:id,title')
            ->when($request->filled('album_id'), fn ($q) => $q->where('album_id', (int) $request->input('album_id')))
            ->when($request->filled('keyword'), fn ($q) => $q->where('title', 'like', '%'.$request->input('keyword').'%'))
            ->orderBy('album_id')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->paginate($perPage);

        $items = $paginator->getCollection()->map(fn (Track $track) => [
            'id' => $track->id,
            'album_id' => (int) $track->album_id,
            'album_title' => $track->album?->title,
            'title' => $track->title,
            'cover_url' => $track->cover_url,
            'duration_sec' => $track->duration_sec === null ? null : (int) $track->duration_sec,
            'url' => $track->url,
            'status' => (int) $track->status,
            'sort_order' => (int) $track->sort_order,
        ]);

        return Api::ok([
            'items' => $items,
            'albums' => Album::query()->orderBy('sort_order')->orderBy('id')->get(['id', 'title']),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function store(StoreTrackRequest $request, AudioStorageService $storage): JsonResponse
    {
        $data = $request->validated();
        $albumId = (int) $data['album_id'];
        $audioKey = null;
        $coverKey = null;

        try {
            $audio = $storage->storeAudio($request->file('audio'), $albumId);
            $audioKey = $audio['object_key'];

            if ($request->hasFile('cover')) {
                $coverKey = $storage->storeCover($request->file('cover'), $albumId);
            }

            $track = Track::create([
                'album_id' => $albumId,
                'title' => $data['title'],
                'cover_key' => $coverKey,
                'object_key' => $audioKey,
                'mime' => $audio['mime'],
                'size_bytes' => $audio['size_bytes'],
                'duration_sec' => $data['duration_sec'] ?? null,
                'sort_order' => ((int) Track::query()->where('album_id', $albumId)->max('sort_order')) + 1,
                'status' => 1,
            ]);
        } catch (InvalidArgumentException|RuntimeException $e) {
            foreach (array_filter([$audioKey, $coverKey]) as $key) {
                $storage->deleteQuietly($key);
            }

            return Api::fail(42211, $e->getMessage(), null, 422);
        }

        CatalogService::bump();

        return Api::ok(['id' => $track->id], '已上传并上架');
    }

    public function update(UpdateTrackRequest $request, Track $track, AudioStorageService $storage): JsonResponse
    {
        $data = $request->validated();
        $coverKey = null;

        try {
            if ($request->hasFile('cover')) {
                $coverKey = $storage->storeCover($request->file('cover'), $track->album_id);
                if ($coverKey) {
                    $data['cover_key'] = $coverKey;
                }
            }
            unset($data['cover']);
            $track->update($data);
        } catch (InvalidArgumentException|RuntimeException $e) {
            $storage->deleteQuietly($coverKey);

            return Api::fail(42211, $e->getMessage(), null, 422);
        }

        CatalogService::bump();

        return Api::ok(['id' => $track->id, 'status' => (int) $track->status], '已保存');
    }

    public function destroy(Track $track, AudioStorageService $storage): JsonResponse
    {
        $keys = [
            $track->object_key,
            $track->cover_key,
            $track->getRawOriginal('cover_url'),
        ];

        // 软删：保留 DB 记录，播放历史不会变成孤儿引用；桶对象同步清理，不可恢复
        $track->delete();
        $deleted = $storage->deleteMany($keys);
        CatalogService::bump();

        return Api::ok(['objects_removed' => $deleted], '已移除，同步清理桶对象 '.$deleted.' 个');
    }
}
