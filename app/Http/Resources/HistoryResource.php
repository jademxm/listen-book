<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\PlayHistory */
class HistoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'track' => $this->whenLoaded('track', fn () => new TrackResource($this->track)),
            'album_title' => $this->when(
                $this->relationLoaded('track') && $this->track && $this->track->relationLoaded('album'),
                fn () => optional($this->track->album)->title
            ),
            'progress_sec' => $this->progress_sec,
            'duration_sec' => $this->duration_sec,
            'finished' => (bool) $this->finished,
            'last_played_at' => optional($this->last_played_at)->toIso8601String(),
        ];
    }
}
