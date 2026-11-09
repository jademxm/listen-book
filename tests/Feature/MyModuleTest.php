<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Album;
use App\Models\PlayEvent;
use App\Models\PlayHistory;
use App\Models\Track;
use App\Models\WxUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MyModuleTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_events_are_idempotent_and_accumulate_only_forward_progress(): void
    {
        [$user, $track] = $this->seedTrack();
        $base = Carbon::now('Asia/Shanghai');

        $start = $this->event('e1', $track->id, 'start', 0, $base->copy()->subSeconds(100));
        $tick = $this->event('e2', $track->id, 'tick', 60, $base->copy()->subSeconds(40));

        $this->postJson('/api/v1/me/play-events', ['events' => [$start, $tick]])
            ->assertOk()
            ->assertJsonPath('data.accepted', 2);

        $this->assertSame(60, (int) PlayEvent::where('wx_user_id', $user->id)->sum('effective_sec'));

        $this->postJson('/api/v1/me/play-events', ['events' => [$start, $tick]])
            ->assertOk()
            ->assertJsonPath('data.duplicated', 2)
            ->assertJsonPath('data.accepted', 0);

        $this->assertSame(60, (int) PlayEvent::where('wx_user_id', $user->id)->sum('effective_sec'));

        // 暂停后恢复：start 重置基准，再听 60 秒 → 累计 120 秒（暂停期间不计入）
        $resume = $this->event('e3', $track->id, 'start', 60, $base->copy()->subSeconds(30));
        $tick2 = $this->event('e4', $track->id, 'tick', 120, $base->copy()->subSeconds(5));
        $this->postJson('/api/v1/me/play-events', ['events' => [$resume, $tick2]])->assertOk();

        $this->assertSame(120, (int) PlayEvent::where('wx_user_id', $user->id)->sum('effective_sec'));
    }

    public function test_catchup_is_capped_by_elapsed_time_and_track_duration(): void
    {
        [, $track] = $this->seedTrack(600);
        $base = Carbon::now('Asia/Shanghai');

        $this->postJson('/api/v1/me/play-events', ['events' => [
            $this->event('s1', $track->id, 'start', 0, $base->copy()->subSeconds(700)),
            $this->event('c1', $track->id, 'catchup', 1200, $base->copy()->subSeconds(100)),
        ]])->assertOk();

        // delta=1200，但真实经过 600 秒、曲目剩余 600 秒 → 只能记 600 秒
        $this->assertSame(600, (int) PlayEvent::sum('effective_sec'));
    }

    public function test_seek_jump_is_not_counted_as_listening(): void
    {
        [, $track] = $this->seedTrack();
        $base = Carbon::now('Asia/Shanghai');

        $this->postJson('/api/v1/me/play-events', ['events' => [
            $this->event('a1', $track->id, 'start', 0, $base->copy()->subSeconds(100)),
            $this->event('a2', $track->id, 'tick', 20, $base->copy()->subSeconds(80)),
            $this->event('a3', $track->id, 'start', 500, $base->copy()->subSeconds(60)),
            $this->event('a4', $track->id, 'tick', 520, $base->copy()->subSeconds(40)),
        ]])->assertOk();

        $this->assertSame(40, (int) PlayEvent::sum('effective_sec'));
    }

    public function test_event_user_comes_from_token_only(): void
    {
        [$user, $track] = $this->seedTrack();
        $other = WxUser::create(['openid' => 'openid-other']);

        $event = $this->event('fake1', $track->id, 'start', 0, Carbon::now('Asia/Shanghai')->subSeconds(10));
        $event['wx_user_id'] = $other->id;
        $event['user_id'] = $other->id;

        $this->postJson('/api/v1/me/play-events', ['events' => [$event]])->assertOk();

        $this->assertSame(1, PlayEvent::where('wx_user_id', $user->id)->count());
        $this->assertSame(0, PlayEvent::where('wx_user_id', $other->id)->count());
    }

    public function test_invalid_time_or_unknown_track_is_rejected(): void
    {
        [, $track] = $this->seedTrack();

        $this->postJson('/api/v1/me/play-events', ['events' => [
            $this->event('future', $track->id, 'tick', 10, Carbon::now('Asia/Shanghai')->addHour()),
            $this->event('ghost', 999999, 'tick', 10, Carbon::now('Asia/Shanghai')->subMinute()),
        ]])
            ->assertOk()
            ->assertJsonPath('data.accepted', 0)
            ->assertJsonPath('data.rejected', 2);

        $this->assertSame(0, PlayEvent::count());
    }

    public function test_batch_over_50_events_is_rejected_by_validation(): void
    {
        [, $track] = $this->seedTrack(); // seedTrack 会同时完成 Sanctum 登录
        $events = [];
        foreach (range(1, 51) as $i) {
            $events[] = $this->event('b'.$i, $track->id, 'start', 0, Carbon::now('Asia/Shanghai')->subMinute());
        }

        $this->postJson('/api/v1/me/play-events', ['events' => $events])->assertStatus(422);
    }

    public function test_stats_returns_scores_albums_and_listening_metrics(): void
    {
        [$user, $album, $tracks] = $this->seedAlbumWithTracks();
        $now = Carbon::now('Asia/Shanghai');

        PlayHistory::create(['wx_user_id' => $user->id, 'track_id' => $tracks[0]->id, 'album_id' => $album->id,
            'progress_sec' => 600, 'duration_sec' => 600, 'finished' => 1, 'last_played_at' => $now->copy()->subMinutes(3)]);
        PlayHistory::create(['wx_user_id' => $user->id, 'track_id' => $tracks[1]->id, 'album_id' => $album->id,
            'progress_sec' => 600, 'duration_sec' => 600, 'finished' => 1, 'last_played_at' => $now->copy()->subMinutes(2)]);
        PlayHistory::create(['wx_user_id' => $user->id, 'track_id' => $tracks[2]->id, 'album_id' => $album->id,
            'progress_sec' => 30, 'duration_sec' => 600, 'finished' => 0, 'last_played_at' => $now->copy()->subMinute()]);

        $this->postJson('/api/v1/me/play-events', ['events' => [
            $this->event('st1', $tracks[2]->id, 'start', 0, $now->copy()->subSeconds(150)),
            $this->event('st2', $tracks[2]->id, 'tick', 120, $now->copy()->subSeconds(30)),
        ]])->assertOk();

        $this->getJson('/api/v1/me/stats')
            ->assertOk()
            ->assertJsonPath('data.finished_tracks', 2)
            ->assertJsonPath('data.finished_content_sec', 1200)
            ->assertJsonPath('data.listened_albums', 1)
            ->assertJsonPath('data.history_count', 3)
            ->assertJsonPath('data.max_finished_track_sec', 600)
            ->assertJsonPath('data.total_listen_sec', 120)
            ->assertJsonPath('data.today_listen_sec', 120)
            ->assertJsonPath('data.streak_days', 1)
            ->assertJsonCount(84, 'data.daily')
            ->assertJsonPath('data.albums.0.published_tracks', 3)
            ->assertJsonPath('data.albums.0.finished_tracks', 2)
            ->assertJsonPath('data.last.track_id', $tracks[2]->id)
            ->assertJsonPath('data.last.album_id', $album->id);

        // 下架一集后完成度分母按当前上架重算，累计收听不受影响
        $tracks[2]->update(['status' => 0]);
        $this->getJson('/api/v1/me/stats')
            ->assertOk()
            ->assertJsonPath('data.albums.0.published_tracks', 2)
            ->assertJsonPath('data.total_listen_sec', 120);
    }

    public function test_clearing_history_also_clears_events_and_resets_stats(): void
    {
        [$user, $track] = $this->seedTrack();
        PlayHistory::create(['wx_user_id' => $user->id, 'track_id' => $track->id, 'album_id' => 1,
            'progress_sec' => 100, 'duration_sec' => 600, 'finished' => 0, 'last_played_at' => now()]);
        $this->postJson('/api/v1/me/play-events', ['events' => [
            $this->event('cl1', $track->id, 'start', 0, Carbon::now('Asia/Shanghai')->subSeconds(100)),
            $this->event('cl2', $track->id, 'tick', 90, Carbon::now('Asia/Shanghai')->subSeconds(10)),
        ]])->assertOk();

        $this->assertSame(90, (int) PlayEvent::where('wx_user_id', $user->id)->sum('effective_sec'));

        $this->deleteJson('/api/v1/me/histories')->assertOk();

        $this->assertSame(0, PlayEvent::count());
        $this->getJson('/api/v1/me/stats')
            ->assertOk()
            ->assertJsonPath('data.total_listen_sec', 0)
            ->assertJsonPath('data.streak_days', 0)
            ->assertJsonPath('data.history_count', 0);
    }

    public function test_stats_and_events_require_authentication(): void
    {
        $this->getJson('/api/v1/me/stats')->assertStatus(401);
        $this->postJson('/api/v1/me/play-events', ['events' => [
            $this->event('noauth', 1, 'start', 0, Carbon::now('Asia/Shanghai')),
        ]])->assertStatus(401);
    }

    public function test_cross_midnight_listening_is_split_by_beijing_date(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-09 00:01:00', 'Asia/Shanghai'));

        try {
            [, $track] = $this->seedTrack(600);

            $this->postJson('/api/v1/me/play-events', ['events' => [
                $this->event('mid1', $track->id, 'start', 0, '2026-11-08T23:59:00+08:00'),
                $this->event('mid2', $track->id, 'catchup', 120, '2026-11-09T00:01:00+08:00'),
            ]])->assertOk();

            $this->assertSame(120, (int) PlayEvent::sum('effective_sec'));
            $this->assertSame(['2026-11-08', '2026-11-09'], PlayEvent::query()
                ->where('effective_sec', '>', 0)
                ->orderBy('event_date')
                ->pluck('event_date')
                ->map(fn ($date) => (string) $date)
                ->all());
            $this->assertSame([60, 60], PlayEvent::query()
                ->where('effective_sec', '>', 0)
                ->orderBy('event_date')
                ->pluck('effective_sec')
                ->all());
        } finally {
            Carbon::setTestNow();
        }
    }

    private function seedTrack(int $duration = 600): array
    {
        $album = Album::create(['title' => '测试专辑', 'cover_key' => 'cover/1/a.png', 'sort_order' => 1, 'status' => 1]);
        $track = Track::create([
            'album_id' => $album->id, 'title' => '第一集', 'object_key' => 'audio/1/a.mp3',
            'sort_order' => 1, 'status' => 1, 'duration_sec' => $duration,
        ]);
        $user = WxUser::create(['openid' => 'openid-my-'.uniqid()]);
        Sanctum::actingAs($user, ['*'], 'wx');

        return [$user, $track];
    }

    private function seedAlbumWithTracks(): array
    {
        $album = Album::create(['title' => '测试专辑', 'cover_key' => 'cover/2/a.png', 'sort_order' => 1, 'status' => 1]);
        $tracks = [];
        foreach (range(1, 3) as $i) {
            $tracks[] = Track::create([
                'album_id' => $album->id, 'title' => '第'.$i.'集', 'object_key' => 'audio/2/'.$i.'.mp3',
                'sort_order' => $i, 'status' => 1, 'duration_sec' => 600,
            ]);
        }
        $user = WxUser::create(['openid' => 'openid-stats-'.uniqid()]);
        Sanctum::actingAs($user, ['*'], 'wx');

        return [$user, $album, $tracks];
    }

    private function event(string $id, int $trackId, string $type, int $position, $time, string $session = 'session-1'): array
    {
        return [
            'client_event_id' => $id,
            'session_id' => $session,
            'track_id' => $trackId,
            'album_id' => 1,
            'type' => $type,
            'position_sec' => $position,
            'occurred_at' => $time instanceof Carbon ? $time->toIso8601String() : (string) $time,
        ];
    }
}
