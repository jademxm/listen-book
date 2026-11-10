<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAlbumRequest;
use App\Http\Requests\UpdateAlbumRequest;
use App\Models\Album;
use App\Models\PlayEvent;
use App\Models\PlayHistory;
use App\Models\Track;
use App\Services\AudioStorageService;
use App\Services\CatalogService;
use App\Support\Api;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class AlbumController extends Controller
{
    public function index(): JsonResponse
    {
        // 只数已上架音频，与 GET /api/v1/albums 的 track_count 同口径
        $albums = Album::query()
            ->withCount(['tracks' => fn ($query) => $query->where('status', 1)])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'title', 'description', 'cover_key', 'cover_url', 'sort_order', 'status'])
            ->map(fn (Album $album) => [
                'id' => $album->id,
                'title' => $album->title,
                'description' => $album->description,
                'cover_url' => $album->cover_url,
                'sort_order' => (int) $album->sort_order,
                'status' => (int) $album->status,
                'tracks_count' => (int) $album->tracks_count,
            ]);

        return Api::ok(['items' => $albums]);
    }

    public function store(StoreAlbumRequest $request, AudioStorageService $storage): JsonResponse
    {
        $data = $request->validated();
        $album = null;
        $coverKey = null;

        try {
            $album = Album::create([
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'sort_order' => ((int) Album::query()->max('sort_order')) + 1,
                'status' => 1,
            ]);

            if ($request->hasFile('cover')) {
                $coverKey = $storage->storeCover($request->file('cover'), $album->id);
                if ($coverKey) {
                    $album->update(['cover_key' => $coverKey]);
                }
            }
        } catch (InvalidArgumentException|RuntimeException $e) {
            $storage->deleteQuietly($coverKey);
            if ($album) {
                $album->delete();
            }

            return Api::fail(42210, $e->getMessage(), null, 422);
        }

        CatalogService::bump();

        return Api::ok(['id' => $album->id], '已创建');
    }

    public function update(UpdateAlbumRequest $request, Album $album, AudioStorageService $storage): JsonResponse
    {
        $data = $request->validated();
        $coverKey = null;

        try {
            if ($request->hasFile('cover')) {
                $coverKey = $storage->storeCover($request->file('cover'), $album->id);
                if ($coverKey) {
                    $data['cover_key'] = $coverKey;
                }
            }
            unset($data['cover']);
            $album->update($data);
        } catch (InvalidArgumentException|RuntimeException $e) {
            $storage->deleteQuietly($coverKey);

            return Api::fail(42210, $e->getMessage(), null, 422);
        }

        CatalogService::bump();

        return Api::ok(['id' => $album->id], '已保存');
    }

    public function destroy(Album $album, AudioStorageService $storage): JsonResponse
    {
        $albumId = $album->id;
        $tracks = Track::query()
            ->where('album_id', $albumId)
            ->get(['id', 'object_key', 'cover_key', 'cover_url']);
        $trackIds = $tracks->pluck('id')->all();

        $keys = [$album->cover_key, $album->getRawOriginal('cover_url')];
        foreach ($tracks as $track) {
            $keys[] = $track->object_key;
            $keys[] = $track->cover_key;
            $keys[] = $track->getRawOriginal('cover_url');
        }

        // 彻底删：主记录、播放历史、收听事件在一个事务里清干净，不留孤儿引用
        DB::transaction(function () use ($album, $albumId, $trackIds): void {
            if ($trackIds !== []) {
                PlayHistory::query()->whereIn('track_id', $trackIds)->delete();
                PlayEvent::query()->whereIn('track_id', $trackIds)->delete();
            }
            // 冗余的 album_id 列：历史行可能已无对应 track，按专辑维度再兜底一次
            PlayHistory::query()->where('album_id', $albumId)->delete();
            PlayEvent::query()->where('album_id', $albumId)->delete();
            Track::query()->where('album_id', $albumId)->delete();
            $album->delete();
        });

        $deleted = $storage->deleteMany($keys);
        CatalogService::bump();

        return Api::ok(
            ['tracks_removed' => $tracks->count(), 'objects_removed' => $deleted],
            '已彻底删除，同步清理桶对象 '.$deleted.' 个'
        );
    }
}
