<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 后台运营面板聚合（只读，SQL 内完成 GROUP BY，不捞明细到 PHP）。
 *
 * 口径：
 *  - 归属一律 join tracks 取当前 album_id，不用两张收听表里冗余的 album_id 快照，
 *    曲目换专辑后数据跟着新专辑走；
 *  - 「播放次数」从现有数据算不出来：play_histories 是 (user, track) 覆盖式 upsert，
 *    tracks.play_count 从未写入，因此只输出听人数、有效收听秒数、听完集数；
 *  - 有效收听秒数沿用 StatsService / SRS FR-08：play_events.effective_sec 之和。
 */
class AdminStatsService
{
    public const TIMEZONE = 'Asia/Shanghai';

    /** @return array<string, mixed> */
    public function build(int $days = 30): array
    {
        $days = max(1, min($days, 90));
        $today = Carbon::now(self::TIMEZONE);
        $from = $today->copy()->subDays($days - 1)->toDateString();

        $overview = [
            'users' => (int) DB::table('wx_users')->count(),
            'published_albums' => (int) DB::table('albums')->where('status', 1)->count(),
            'published_tracks' => (int) DB::table('tracks')->where('status', 1)->count(),
            'total_listen_sec' => (int) DB::table('play_events')->sum('effective_sec'),
            'active_users' => (int) DB::table('play_events')
                ->where('event_date', '>=', $from)
                ->distinct()->count('wx_user_id'),
        ];

        $publishedByAlbum = DB::table('tracks')
            ->where('status', 1)
            ->groupBy('album_id')
            ->selectRaw('album_id, COUNT(*) AS cnt')
            ->get()
            ->pluck('cnt', 'album_id');

        $listenByAlbum = DB::table('play_events as e')
            ->join('tracks as t', 't.id', '=', 'e.track_id')
            ->where('e.event_date', '>=', $from)
            ->groupBy('t.album_id')
            ->selectRaw('t.album_id, SUM(e.effective_sec) AS sec, COUNT(DISTINCT e.wx_user_id) AS users')
            ->get()
            ->keyBy('album_id');

        // 听完集数为累计口径（finished 是历史状态，不按时间窗裁剪）
        $finishedByAlbum = DB::table('play_histories as h')
            ->join('tracks as t', 't.id', '=', 'h.track_id')
            ->where('h.finished', 1)
            ->groupBy('t.album_id')
            ->selectRaw('t.album_id, COUNT(*) AS cnt')
            ->get()
            ->pluck('cnt', 'album_id');

        // 以上架专辑为主表：零收听的专辑也出现在榜里（运营要能看出「哪本没人点」）
        $albumRows = [];
        foreach (DB::table('albums')->where('status', 1)->orderBy('sort_order')->orderBy('id')->get(['id', 'title']) as $album) {
            $albumId = (int) $album->id;
            $listen = $listenByAlbum->get($albumId);
            $albumRows[] = [
                'album_id' => $albumId,
                'title' => (string) $album->title,
                'listeners' => (int) ($listen->users ?? 0),
                'listen_sec' => (int) ($listen->sec ?? 0),
                'finished_tracks' => (int) ($finishedByAlbum[$albumId] ?? 0),
                'published_tracks' => (int) ($publishedByAlbum[$albumId] ?? 0),
            ];
        }
        usort($albumRows, fn (array $a, array $b) => $b['listen_sec'] <=> $a['listen_sec']);

        $trackRows = DB::table('play_events as e')
            ->join('tracks as t', 't.id', '=', 'e.track_id')
            ->where('e.event_date', '>=', $from)
            ->groupBy('e.track_id', 't.title', 't.album_id')
            ->selectRaw('e.track_id, t.title, t.album_id, SUM(e.effective_sec) AS sec, COUNT(DISTINCT e.wx_user_id) AS users')
            ->orderByDesc('sec')
            ->limit(20)
            ->get()
            ->map(fn ($row) => [
                'track_id' => (int) $row->track_id,
                'album_id' => (int) $row->album_id,
                'title' => (string) $row->title,
                'listeners' => (int) $row->users,
                'listen_sec' => (int) $row->sec,
            ])->all();

        $daily = DB::table('play_events')
            ->where('event_date', '>=', $from)
            ->groupBy('event_date')
            ->selectRaw('event_date, SUM(effective_sec) AS sec, COUNT(DISTINCT wx_user_id) AS users')
            ->get()
            ->keyBy('event_date');

        $trend = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = $today->copy()->subDays($i)->toDateString();
            $row = $daily->get($date);
            $trend[] = [
                'date' => $date,
                'listen_sec' => (int) ($row->sec ?? 0),
                'users' => (int) ($row->users ?? 0),
            ];
        }

        return [
            'days' => $days,
            'from' => $from,
            'today' => $today->toDateString(),
            'overview' => $overview,
            'albums' => $albumRows,
            'tracks' => $trackRows,
            'trend' => $trend,
        ];
    }
}
