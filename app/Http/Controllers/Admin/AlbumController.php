<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAlbumRequest;
use App\Http\Requests\UpdateAlbumRequest;
use App\Models\Album;
use App\Models\Track;
use App\Services\AudioStorageService;
use App\Services\CatalogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use InvalidArgumentException;
use RuntimeException;

class AlbumController extends Controller
{
    public function index(): View
    {
        $albums = Album::query()->withCount('tracks')->orderBy('sort_order')->orderBy('id')->get();

        return view('admin.albums.index', compact('albums'));
    }

    public function store(StoreAlbumRequest $request, AudioStorageService $storage): RedirectResponse
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
                $album->forceDelete();
            }

            return back()->withErrors(['cover' => $e->getMessage()])->withInput();
        }

        CatalogService::bump();

        return back()->with('status', '专辑已创建');
    }

    public function update(UpdateAlbumRequest $request, Album $album, AudioStorageService $storage): RedirectResponse
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

            return back()->withErrors(['cover' => $e->getMessage()]);
        }

        CatalogService::bump();

        return back()->with('status', '专辑已更新');
    }

    public function destroy(Album $album, AudioStorageService $storage): RedirectResponse
    {
        // withTrashed：把此前已软删、但桶对象还在的音频也一并清掉
        $tracks = Track::withTrashed()
            ->where('album_id', $album->id)
            ->get(['id', 'object_key', 'cover_key', 'cover_url']);

        $keys = [$album->cover_key, $album->getRawOriginal('cover_url')];
        foreach ($tracks as $track) {
            $keys[] = $track->object_key;
            $keys[] = $track->cover_key;
            $keys[] = $track->getRawOriginal('cover_url');
        }

        DB::transaction(function () use ($album): void {
            Track::where('album_id', $album->id)->delete(); // 软删
            $album->delete();
        });

        $deleted = $storage->deleteMany($keys);
        CatalogService::bump();

        return back()->with('status', '专辑及 '.$tracks->count().' 条音频已移除，同步清理桶对象 '.$deleted.' 个');
    }
}
