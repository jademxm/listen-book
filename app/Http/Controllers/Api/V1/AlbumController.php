<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\AlbumResource;
use App\Http\Resources\TrackResource;
use App\Models\Album;
use App\Models\Track;
use App\Services\CatalogService;
use App\Support\Api;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AlbumController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $albums = CatalogService::remember('albums', fn () => Album::query()
            ->published()
            ->withCount(['tracks' => fn ($q) => $q->where('status', 1)])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (Album $album) => (new AlbumResource($album))->resolve())
            ->all()
        );

        return Api::ok($this->paginate($albums, $request));
    }

    public function tracks(Request $request, int $album): JsonResponse
    {
        if (! Album::query()->published()->whereKey($album)->exists()) {
            return Api::fail(4040, '专辑不存在或已下架', null, 404);
        }

        $tracks = CatalogService::remember('album:tracks:'.$album, fn () => Track::query()
            ->where('album_id', $album)
            ->published()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (Track $track) => (new TrackResource($track))->resolve())
            ->all()
        );

        return Api::ok($this->paginate($tracks, $request));
    }

    private function paginate(array $items, Request $request): array
    {
        $page = max(1, (int) $request->query('page', 1));
        $perPage = min(max((int) $request->query('per_page', 50), 1), 100);
        $total = count($items);

        return [
            'list' => array_slice($items, ($page - 1) * $perPage, $perPage),
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'has_more' => $page * $perPage < $total,
            'catalog_version' => CatalogService::version(),
        ];
    }
}
