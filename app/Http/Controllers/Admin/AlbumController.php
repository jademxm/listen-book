<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAlbumRequest;
use App\Models\Album;
use App\Services\AudioStorageService;
use App\Services\CatalogService;
use Illuminate\Http\RedirectResponse;
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
                $album->update(['cover_url' => $storage->toUrl($coverKey)]);
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
}
