<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PlayEvent;
use App\Models\Track;
use App\Models\WxUser;
use App\Support\CoverUrl;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 「我的」页聚合数据（SRS v1.0 IF-01）。
 *
 * 两套时长口径严格分开：
 *  - finished_content_sec = 已听完集数的内容时长之和（来自 play_histories）；
 *  - total_listen_sec     = 有效收听秒数之和（来自 play_events）。
 *
 * 只查当前登录用户，4 条 SQL：历史 join、上架曲目数、事件总和、按日聚合。
 */
class StatsService
{
    public const TIMEZONE = 'Asia/Shanghai';
    public const HEATMAP_DAYS = 84;
    public const ACTIVE_DAY_SEC = 60;

    public function build(WxUser $user): array
    {
        $rows = DB::table('play_histories as h')
            ->join('tracks as t', 't.id', '=', 'h.track_id')
            ->join('albums as a', 'a.id', '=', 't.album_id')
            ->where('h.wx_user_id', $user->id)
            ->where('t.status', 1)
            ->whereNull('t.deleted_at')
            ->where('a.status', 1)
            ->whereNull('a.deleted_at')
            ->orderByDesc('h.last_played_at')
            ->get([
                'h.track_id', 'h.progress_sec', 'h.duration_sec', 'h.finished', 'h.last_played_at',
                't.album_id', 't.title as track_title', 't.duration_sec as track_duration',
                't.cover_key as track_cover_key', 't.cover_url as track_cover_url',
                'a.title as album_title', 'a.cover_key as album_cover_key', 'a.cover_url as album_cover_url',
            ]);

        $finishedTracks = 0;
        $finishedContentSec = 0;
        $maxFinishedTrackSec = 0;
        $albumIds = [];
        $albumStats = [];

        foreach ($rows as $row) {
            $albumId = (int) $row->album_id;
            $albumIds[$albumId] = true;
            $duration = (int) ($row->duration_sec ?: $row->track_duration ?: 0);
            $finished = (int) $row->finished === 1;

            if ($finished) {
                $finishedTracks++;
                $finishedContentSec += $duration;
                $maxFinishedTrackSec = max($maxFinishedTrackSec, $duration);
            }

            if (! isset($albumStats[$albumId])) {
                $albumStats[$albumId] = [
                    'album_id' => $albumId,
                    'title' => (string) $row->album_title,
                    'cover_url' => CoverUrl::resolve($row->album_cover_key, $row->album_cover_url),
                    'finished_tracks' => 0,
                    'last_played_at' => $row->last_played_at,
                ];
            }

            if ($finished) {
                $albumStats[$albumId]['finished_tracks']++;
            }
        }

        $published = Track::query()
            ->published()
            ->whereIn('album_id', array_keys($albumIds))
            ->groupBy('album_id')
            ->selectRaw('album_id, COUNT(*) AS total')
            ->get()
            ->pluck('total', 'album_id');

        $albums = [];
        foreach ($albumStats as $albumId => $item) {
            $total = (int) ($published[$albumId] ?? 0);
            if ($total <= 0) {
                continue; // 分母为 0 的专辑不展示
            }

            $item['published_tracks'] = $total;
            $item['finished_tracks'] = min($item['finished_tracks'], $total);
            $item['last_played_at'] = $item['last_played_at']
                ? Carbon::parse($item['last_played_at'])->setTimezone(self::TIMEZONE)->toIso8601String()
                : null;
            $albums[] = $item;
        }

        $last = null;
        if ($rows->isNotEmpty()) {
            $first = $rows->first();
            $last = [
                'track_id' => (int) $first->track_id,
                'album_id' => (int) $first->album_id, // 「我的」页异曲目续播需要据此拉专辑列表
                'title' => (string) $first->track_title,
                'cover_url' => CoverUrl::resolve($first->track_cover_key, $first->track_cover_url),
                'album_title' => (string) $first->album_title,
                'progress_sec' => (int) $first->progress_sec,
                'duration_sec' => (int) ($first->duration_sec ?: $first->track_duration ?: 0),
            ];
        }

        $totalListenSec = (int) PlayEvent::query()->where('wx_user_id', $user->id)->sum('effective_sec');
        $allDaily = PlayEvent::query()
            ->where('wx_user_id', $user->id)
            ->groupBy('event_date')
            ->selectRaw('event_date, SUM(effective_sec) AS sec')
            ->get()
            ->pluck('sec', 'event_date');

        $today = Carbon::now(self::TIMEZONE);
        $daily = [];
        for ($i = self::HEATMAP_DAYS - 1; $i >= 0; $i--) {
            $date = $today->copy()->subDays($i)->toDateString();
            $daily[] = ['date' => $date, 'sec' => (int) ($allDaily[$date] ?? 0)];
        }

        $todaySec = (int) ($allDaily[$today->toDateString()] ?? 0);

        return [
            'finished_tracks' => $finishedTracks,
            'finished_content_sec' => $finishedContentSec,
            'listened_albums' => count($albumIds),
            'history_count' => $rows->count(),
            'max_finished_track_sec' => $maxFinishedTrackSec,
            'total_listen_sec' => $totalListenSec,
            'streak_days' => $this->streakDays($allDaily, $todaySec, $today),
            'today_listen_sec' => $todaySec,
            'daily' => $daily,
            'albums' => $albums,
            'last' => $last,
        ];
    }

    /** 今日未达标则从昨天起算；向前累计连续「有效收听日」。 */
    private function streakDays($allDaily, int $todaySec, Carbon $today): int
    {
        $cursor = $today->copy();
        if ($todaySec < self::ACTIVE_DAY_SEC) {
            $cursor->subDay();
        }

        $streak = 0;
        while ((int) ($allDaily[$cursor->toDateString()] ?? 0) >= self::ACTIVE_DAY_SEC) {
            $streak++;
            $cursor->subDay();
        }

        return $streak;
    }
}
