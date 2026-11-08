<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTrackRequest;
use App\Http\Requests\UpdateTrackRequest;
use App\Models\Album;
use App\Models\Track;
use App\Services\AudioStorageService;
use App\Services\CatalogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;
use RuntimeException;

class TrackController extends Controller
{
    public function index(Request $request): View
    {
        $albums = Album::query()->orderBy('sort_order')->orderBy('id')->get(['id', 'title']);

        $tracks = Track::query()
            ->with('album:id,title')
            ->when($request->filled('album_id'), fn ($q) => $q->where('album_id', (int) $request->input('album_id')))
            ->when($request->filled('keyword'), fn ($q) => $q->where('title', 'like', '%'.$request->input('keyword').'%'))
            ->orderBy('album_id')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->paginate(30)
            ->withQueryString();

        return view('admin.tracks.index', compact('tracks', 'albums'));
    }

    public function store(StoreTrackRequest $request, AudioStorageService $storage): RedirectResponse
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

            Track::create([
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

            return back()->withErrors(['audio' => $e->getMessage()])->withInput();
        }

        CatalogService::bump();

        return back()->with('status', '音频已上传并上架');
    }

    public function update(UpdateTrackRequest $request, Track $track, AudioStorageService $storage): RedirectResponse
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

            return back()->withErrors(['cover' => $e->getMessage()]);
        }

        CatalogService::bump();

        return back()->with('status', '已保存');
    }

    public function destroy(Track $track): RedirectResponse
    {
        $track->delete(); // 软删；COS 对象保留，便于误删恢复
        CatalogService::bump();

        return back()->with('status', '已下架并移除（软删除）');
    }
}
