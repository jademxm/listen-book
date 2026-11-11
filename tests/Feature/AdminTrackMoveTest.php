<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Album;
use App\Models\Track;
use App\Services\CatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class AdminTrackMoveTest extends TestCase
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

    private function makeAlbum(string $title): Album
    {
        return Album::create(['title' => $title, 'sort_order' => 0, 'status' => 1]);
    }

    private function makeTrack(Album $album, string $title, int $sort): Track
    {
        return Track::create([
            'album_id' => $album->id,
            'title' => $title,
            'object_key' => 'audio/'.$album->id.'/'.$title.'.mp3',
            'sort_order' => $sort,
            'status' => 1,
        ]);
    }

    private function seedListening(Track $track, Album $album): void
    {
        DB::table('play_histories')->insert([
            'wx_user_id' => 1,
            'track_id' => $track->id,
            'album_id' => $album->id,
            'progress_sec' => 120,
            'finished' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('play_events')->insert([
            'wx_user_id' => 1,
            'track_id' => $track->id,
            'album_id' => $album->id,
            'session_id' => 'sess-1',
            'client_event_id' => 'evt-'.$track->id,
            'type' => 'tick',
            'position_sec' => 120,
            'effective_sec' => 30,
            'event_date' => now('Asia/Shanghai')->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_moving_track_keeps_object_and_rewrites_redundant_album_ids(): void
    {
        $this->asAdmin();
        $from = $this->makeAlbum('上册');
        $to = $this->makeAlbum('下册');
        $track = $this->makeTrack($from, '第01集', 1);
        $this->makeTrack($to, '已有集', 5);
        $this->seedListening($track, $from);
        $objectKey = $track->object_key;
        $before = CatalogService::version();

        $this->patchJson('/api/admin/tracks/'.$track->id, ['title' => '第01集', 'album_id' => $to->id])
            ->assertOk()
            ->assertJsonPath('data.album_id', $to->id);

        $track->refresh();
        $this->assertSame($to->id, (int) $track->album_id);
        $this->assertSame($objectKey, $track->object_key, '换专辑不搬桶对象');
        $this->assertSame(6, (int) $track->sort_order, '排到目标专辑末尾');
        $this->assertSame($to->id, (int) DB::table('play_histories')->where('track_id', $track->id)->value('album_id'));
        $this->assertSame($to->id, (int) DB::table('play_events')->where('track_id', $track->id)->value('album_id'));
        $this->assertGreaterThan($before, CatalogService::version());
    }

    public function test_moving_track_rejects_unknown_album(): void
    {
        $this->asAdmin();
        $from = $this->makeAlbum('上册');
        $track = $this->makeTrack($from, '第01集', 1);

        $this->patchJson('/api/admin/tracks/'.$track->id, ['album_id' => 999999])
            ->assertStatus(422);

        $this->assertSame($from->id, (int) $track->fresh()->album_id);
    }

    public function test_deleting_old_album_after_move_keeps_moved_listening_data(): void
    {
        $this->asAdmin();
        Storage::fake('cos');
        $from = $this->makeAlbum('上册');
        $to = $this->makeAlbum('下册');
        $track = $this->makeTrack($from, '第01集', 1);
        $this->seedListening($track, $from);

        $this->patchJson('/api/admin/tracks/'.$track->id, ['album_id' => $to->id])->assertOk();
        $this->deleteJson('/api/admin/albums/'.$from->id)->assertOk();

        $this->assertSame(1, DB::table('play_histories')->where('track_id', $track->id)->count());
        $this->assertSame(1, DB::table('play_events')->where('track_id', $track->id)->count());
        $this->assertNotNull(Track::find($track->id));
    }
}
