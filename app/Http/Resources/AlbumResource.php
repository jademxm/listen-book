<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Album */
class AlbumResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'cover_url' => $this->cover_url,
            'description' => $this->description,
            'sort_order' => $this->sort_order,
            'track_count' => isset($this->tracks_count) ? (int) $this->tracks_count : 0,
        ];
    }
}
