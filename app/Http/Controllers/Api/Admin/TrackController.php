<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTrackRequest;
use App\Http\Requests\UpdateTrackRequest;
use App\Models\Album;
use App\Models\PlayEvent;
use App\Models\PlayHistory;
use App\Models\Track;
use App\Services\AudioStorageService;
use App\Services\CatalogService;
use App\Support\Api;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
        $oldAlbumId = (int) $track->album_id;
        $newAlbumId = isset($data['album_id']) ? (int) $data['album_id'] : $oldAlbumId;
        $moving = $newAlbumId !== $oldAlbumId;

        try {
            if ($request->hasFile('cover')) {
                // 换专辑时新封面归到目标专辑目录，object_key 保持不变、不搬桶对象
                $coverKey = $storage->storeCover($request->file('cover'), $newAlbumId);
                if ($coverKey) {
                    $data['cover_key'] = $coverKey;
                }
            }
            unset($data['cover']);

            DB::transaction(function () use ($track, $data, $moving, $newAlbumId): void {
                if ($moving) {
                    $data['sort_order'] = ((int) Track::query()->where('album_id', $newAlbumId)->max('sort_order')) + 1;

                    // 两张收听表冗余存了 album_id；删旧专辑时会按它兜底清理，
                    // 不跟着回写就会误删已挪走曲目的历史与事件。
                    PlayHistory::query()->where('track_id', $track->id)->update(['album_id' => $newAlbumId]);
                    PlayEvent::query()->where('track_id', $track->id)->update(['album_id' => $newAlbumId]);
                }

                $track->update($data);
            });
        } catch (\Throwable $e) {
            $storage->deleteQuietly($coverKey);
            if (! $e instanceof InvalidArgumentException && ! $e instanceof RuntimeException) {
                throw $e;
            }

            return Api::fail(42211, $e->getMessage(), null, 422);
        }

        CatalogService::bump();

        return Api::ok([
            'id' => $track->id,
            'status' => (int) $track->status,
            'album_id' => (int) $track->album_id,
        ], '已保存');
    }

    public function destroy(Track $track, AudioStorageService $storage): JsonResponse
    {
        $trackId = $track->id;
        $keys = [
            $track->object_key,
            $track->cover_key,
            $track->getRawOriginal('cover_url'),
        ];

        // 彻底删：曲目、播放历史、收听事件同一事务；桶对象在提交后清理
        DB::transaction(function () use ($trackId): void {
            PlayHistory::query()->where('track_id', $trackId)->delete();
            PlayEvent::query()->where('track_id', $trackId)->delete();
            Track::query()->whereKey($trackId)->delete();
        });

        $deleted = $storage->deleteMany($keys);
        CatalogService::bump();

        return Api::ok(['objects_removed' => $deleted], '已彻底删除，同步清理桶对象 '.$deleted.' 个');
    }
}
