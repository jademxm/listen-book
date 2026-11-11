<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class AdminStatsTest extends TestCase
{
    use RefreshDatabase;

    private function asAdmin(): static
    {
        $admin = Admin::create([
            'username' => 'admin_'.Str::random(6),
            'phone' => '138'.random_int(10000000, 99999999),
            'password' => 'secret123',
            'role' => Admin::ROLE_SUPER,
            'status' => 1,
        ]);

        auth()->forgetGuards();
        JWTAuth::unsetToken();
        $token = (string) JWTAuth::fromUser($admin);

        return $this->withHeader('Authorization', 'Bearer '.$token);
    }

    private function insertAlbum(string $title): int
    {
        return (int) DB::table('albums')->insertGetId([
            'title' => $title, 'sort_order' => 0, 'status' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function insertTrack(int $albumId, string $title): int
    {
        return (int) DB::table('tracks')->insertGetId([
            'album_id' => $albumId, 'title' => $title, 'object_key' => 'audio/'.$title.'.mp3',
            'sort_order' => 1, 'status' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function insertUser(string $openid): int
    {
        return (int) DB::table('wx_users')->insertGetId([
            'openid' => $openid, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function insertEvent(int $trackId, int $albumId, int $userId, int $sec, string $date, string $eid): void
    {
        DB::table('play_events')->insert([
            'wx_user_id' => $userId, 'track_id' => $trackId, 'album_id' => $albumId,
            'session_id' => 'sess-'.$eid, 'client_event_id' => $eid, 'type' => 'tick',
            'position_sec' => $sec, 'effective_sec' => $sec, 'event_date' => $date,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_overview_and_rankings_use_real_metrics_and_include_zero_listen_albums(): void
    {
        $this->asAdmin();
        $today = now('Asia/Shanghai')->toDateString();
        $old = now('Asia/Shanghai')->subDays(40)->toDateString();

        $a1 = $this->insertAlbum('上册');
        $a2 = $this->insertAlbum('下册');
        $t1 = $this->insertTrack($a1, '第01集');
        $this->insertTrack($a2, '没人点的集');
        $u1 = $this->insertUser('open-1');
        $u2 = $this->insertUser('open-2');

        $this->insertEvent($t1, $a1, $u1, 30, $today, 'evt-1');
        $this->insertEvent($t1, $a1, $u2, 20, $today, 'evt-2');
        $this->insertEvent($t1, $a1, $u1, 100, $old, 'evt-3'); // 窗口外，只计入累计

        DB::table('play_histories')->insert([
            'wx_user_id' => $u1, 'track_id' => $t1, 'album_id' => $a1,
            'progress_sec' => 100, 'finished' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $data = $this->getJson('/api/admin/stats?days=7')->assertOk()->json('data');

        $this->assertSame(2, $data['overview']['users']);
        $this->assertSame(2, $data['overview']['published_albums']);
        $this->assertSame(2, $data['overview']['published_tracks']);
        $this->assertSame(150, $data['overview']['total_listen_sec']);
        $this->assertSame(2, $data['overview']['active_users']);
        $this->assertArrayNotHasKey('play_count', $data['overview']);

        $byId = collect($data['albums'])->keyBy('album_id');
        $this->assertSame(50, $byId[$a1]['listen_sec']);
        $this->assertSame(2, $byId[$a1]['listeners']);
        $this->assertSame(1, $byId[$a1]['finished_tracks']);
        $this->assertSame(1, $byId[$a1]['published_tracks']);
        $this->assertSame(0, $byId[$a2]['listen_sec'], '零收听专辑也要出现在榜里');
        $this->assertSame(1, $byId[$a2]['published_tracks']);
        $this->assertCount(7, $data['trend']);
        $this->assertSame(1, count($data['tracks']));
    }

    public function test_stats_follow_current_album_after_track_moves(): void
    {
        $this->asAdmin();
        $a1 = $this->insertAlbum('上册');
        $a2 = $this->insertAlbum('下册');
        $t1 = $this->insertTrack($a1, '第01集');
        $u1 = $this->insertUser('open-1');
        $this->insertEvent($t1, $a1, $u1, 30, now('Asia/Shanghai')->toDateString(), 'evt-move');

        // 事件里的 album_id 是旧快照，只改曲目当前归属
        DB::table('tracks')->where('id', $t1)->update(['album_id' => $a2]);

        $data = $this->getJson('/api/admin/stats?days=30')->assertOk()->json('data');
        $byId = collect($data['albums'])->keyBy('album_id');

        $this->assertSame(0, $byId[$a1]['listen_sec']);
        $this->assertSame(30, $byId[$a2]['listen_sec']);
    }

    public function test_stats_indexes_exist_after_migrations(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('索引断言仅针对测试用 SQLite');
        }

        $names = array_map(
            fn ($row) => $row->name,
            DB::select("SELECT name FROM sqlite_master WHERE type = 'index' AND tbl_name IN ('play_events', 'play_histories')")
        );

        $this->assertContains('play_events_track_id_index', $names);
        $this->assertContains('play_events_event_date_index', $names);
        $this->assertContains('play_histories_track_id_index', $names);
    }
}
