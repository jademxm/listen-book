<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PlayHistory;
use App\Models\Track;
use App\Models\WxUser;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class HistoryService
{
    public function report(WxUser $user, array $data): PlayHistory
    {
        $track = Track::query()->published()->find($data['track_id']);
        if (! $track) {
            throw new ModelNotFoundException('音频不存在或已下架');
        }

        $duration = $data['duration_sec'] ?? $track->duration_sec;
        $progress = max(0, (int) $data['progress_sec']);
        if ($duration && $progress > $duration) {
            $progress = (int) $duration;
        }
        $finished = $duration && $progress >= max(0, (int) $duration - 5);

        $history = PlayHistory::updateOrCreate(
            ['wx_user_id' => $user->id, 'track_id' => $track->id],
            [
                'album_id' => $track->album_id,
                'progress_sec' => $progress,
                'duration_sec' => $duration,
                'finished' => $finished ? 1 : 0,
                'last_played_at' => now(),
            ]
        );

        $user->forceFill(['last_active_at' => now()])->saveQuietly();

        return $history;
    }

    public function clear(WxUser $user): int
    {
        return PlayHistory::where('wx_user_id', $user->id)->delete();
    }
}
