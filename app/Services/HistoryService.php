<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PlayEvent;
use App\Models\PlayHistory;
use App\Models\Track;
use App\Models\WxUser;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

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
        // 「我的」模块 FR-06：清空历史会同时重置累计收听、连续天数、热力格与徽章。
        // 返回值保持原语义（被清掉的历史条数），不把事件数混进去。
        return DB::transaction(function () use ($user) {
            PlayEvent::where('wx_user_id', $user->id)->delete();

            return PlayHistory::where('wx_user_id', $user->id)->delete();
        });
    }
}
