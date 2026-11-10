<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PlayEvent;
use App\Models\Track;
use App\Models\WxUser;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 收听事件入库：幂等去重 + 有效收听秒数结算 + 跨自然日拆分。
 *
 * 口径（SRS v1.0 FR-08）：
 *  - 有效收听 = 播放位置向前推进的部分；暂停、停止、seek 跳变、进度倒退不计入；
 *  - start 事件只建立基准，不产生时长；重听（新会话或从 0 重新 start）可重复累计；
 *  - catchup 额外受「真实经过时间」约束，且一律按曲目时长截断；
 *  - 跨北京自然日的收听段拆成多条记录，各自归属 event_date；
 *  - 用户身份只取 token 推导的 $user，忽略客户端传入的任何用户字段。
 */
class PlayEventService
{
    public const TIMEZONE = 'Asia/Shanghai';
    public const MAX_FUTURE_SEC = 300;
    public const MAX_AGE_DAYS = 365;

    public function ingest(WxUser $user, array $events): array
    {
        $now = Carbon::now(self::TIMEZONE);
        $lowerBound = $now->copy()->subDays(self::MAX_AGE_DAYS);
        $upperBound = $now->copy()->addSeconds(self::MAX_FUTURE_SEC);

        $trackIds = array_values(array_unique(array_map('intval', array_column($events, 'track_id'))));
        $durations = Track::query()->whereIn('id', $trackIds)->pluck('duration_sec', 'id');

        $existing = array_flip(PlayEvent::query()
            ->whereIn('client_event_id', array_column($events, 'client_event_id'))
            ->pluck('client_event_id')
            ->all());

        $states = $this->lastStates($user, $events);

        $accepted = 0;
        $duplicated = 0;
        $rejected = 0;
        $normalized = [];

        foreach ($events as $event) {
            $eventId = (string) $event['client_event_id'];

            if (isset($existing[$eventId])) {
                $duplicated++;
                continue;
            }

            $time = $this->parseTime($event['occurred_at'] ?? null);
            if ($time === null || $time->greaterThan($upperBound) || $time->lessThan($lowerBound)
                || ! $durations->has((int) $event['track_id'])) {
                $rejected++;
                continue;
            }

            $event['_time'] = $time;
            $normalized[] = $event;
        }

        usort($normalized, fn (array $a, array $b) => $a['_time']->getTimestamp() <=> $b['_time']->getTimestamp());

        $rows = [];
        foreach ($normalized as $event) {
            $key = $this->stateKey((string) $event['session_id'], (int) $event['track_id']);
            $position = (int) $event['position_sec'];
            $type = (string) $event['type'];
            $time = $event['_time'];
            $previous = $states[$key] ?? null;

            $effective = 0;
            if ($type !== 'start' && $previous !== null) {
                $delta = $position - $previous['position'];
                if ($delta > 0) {
                    $duration = (int) ($durations[(int) $event['track_id']] ?? 0);
                    $cap = $duration > 0 ? max(0, $duration - $previous['position']) : $delta;
                    $effective = min($delta, $cap);

                    if ($type === 'catchup' && $previous['time'] !== null) {
                        $elapsed = max(0, $time->getTimestamp() - $previous['time']->getTimestamp());
                        $effective = min($effective, $elapsed);
                    }
                }
            }

            $states[$key] = ['position' => $position, 'time' => $time];

            foreach ($this->splitByDate($time, $effective) as $i => $segment) {
                $rows[] = [
                    'wx_user_id' => $user->id,
                    'track_id' => (int) $event['track_id'],
                    'album_id' => isset($event['album_id']) ? (int) $event['album_id'] : null,
                    'session_id' => (string) $event['session_id'],
                    'client_event_id' => $i === 0
                        ? (string) $event['client_event_id']
                        : substr((string) $event['client_event_id'], 0, 60).':d'.$i,
                    'type' => $type,
                    'position_sec' => $position,
                    'effective_sec' => (int) $segment['sec'],
                    'event_date' => $segment['date'],
                    // 统一按北京时间的墙钟字符串落库，与 app.timezone=Asia/Shanghai 自洽
                    'occurred_at' => $time->format('Y-m-d H:i:s'),
                    'created_at' => $now->format('Y-m-d H:i:s'),
                    'updated_at' => $now->format('Y-m-d H:i:s'),
                ];
            }

            $accepted++;
        }

        if ($rows) {
            DB::transaction(function () use ($rows) {
                foreach (array_chunk($rows, 100) as $chunk) {
                    DB::table('play_events')->insertOrIgnore($chunk);
                }
            });
        }

        return compact('accepted', 'duplicated', 'rejected');
    }

    /** 取每个 (session, track) 的最后一条事件，作为本批结算的起点基准。 */
    private function lastStates(WxUser $user, array $events): array
    {
        $sessions = array_values(array_unique(array_column($events, 'session_id')));
        if (! $sessions) {
            return [];
        }

        $latestIds = PlayEvent::query()
            ->where('wx_user_id', $user->id)
            ->whereIn('session_id', $sessions)
            ->groupBy('session_id', 'track_id')
            ->selectRaw('MAX(id) AS last_id')
            ->get()
            ->pluck('last_id')
            ->all();

        if (! $latestIds) {
            return [];
        }

        $states = [];
        foreach (PlayEvent::query()->whereIn('id', $latestIds)->get(['session_id', 'track_id', 'position_sec', 'occurred_at']) as $row) {
            $time = $row->occurred_at instanceof Carbon
                ? $row->occurred_at->copy()->setTimezone(self::TIMEZONE)
                : $this->parseTime($row->occurred_at);

            $states[$this->stateKey((string) $row->session_id, (int) $row->track_id)] = [
                'position' => (int) $row->position_sec,
                'time' => $time,
            ];
        }

        return $states;
    }

    /** 把 [end-effective, end] 这一段时间按北京自然日切开；effective=0 也落一条基准记录。 */
    private function splitByDate(Carbon $end, int $effective): array
    {
        if ($effective <= 0) {
            return [['date' => $end->toDateString(), 'sec' => 0]];
        }

        $segments = [];
        $cursor = $end->copy()->subSeconds($effective);
        $remaining = $effective;

        while ($remaining > 0 && count($segments) < 5) {
            $nextDay = $cursor->copy()->addDay()->startOfDay();
            $boundary = $nextDay->lessThan($end) ? $nextDay : $end;
            $seconds = min($remaining, max(0, $boundary->getTimestamp() - $cursor->getTimestamp()));

            if ($seconds <= 0) {
                $cursor = $boundary;
                continue;
            }

            $date = $boundary->equalTo($end)
                ? $end->toDateString()
                : $boundary->copy()->subSecond()->toDateString();

            $segments[] = ['date' => $date, 'sec' => $seconds];
            $remaining -= $seconds;
            $cursor = $boundary;
        }

        return $segments ?: [['date' => $end->toDateString(), 'sec' => 0]];
    }

    private function stateKey(string $sessionId, int $trackId): string
    {
        return $sessionId.'|'.$trackId;
    }

    private function parseTime(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->setTimezone(self::TIMEZONE);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
