<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Track */
class TrackResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'album_id' => $this->album_id,
            'title' => $this->title,
            'cover_url' => $this->cover_url,
            'duration_sec' => $this->duration_sec,
            'sort_order' => $this->sort_order,
            'url' => $this->url,
        ];
    }
}
