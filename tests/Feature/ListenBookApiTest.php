<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Album;
use App\Models\PlayHistory;
use App\Models\Track;
use App\Models\WxUser;
use App\Services\CatalogService;
use App\Services\ReorderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ListenBookApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_only_published_tracks_are_exposed(): void
    {
        $album = Album::create(['title' => '测试专辑', 'sort_order' => 1, 'status' => 1]);
        Track::create(['album_id' => $album->id, 'title' => '已上架', 'object_key' => 'audio/a.mp3', 'sort_order' => 1, 'status' => 1]);
        Track::create(['album_id' => $album->id, 'title' => '草稿', 'object_key' => 'audio/b.mp3', 'sort_order' => 2, 'status' => 0]);

        $this->getJson('/api/v1/albums/'.$album->id.'/tracks')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonCount(1, 'data.list')
            ->assertJsonPath('data.list.0.title', '已上架');
    }

    public function test_reorder_moves_track_before_target(): void
    {
        $album = Album::create(['title' => '测试专辑', 'sort_order' => 1, 'status' => 1]);
        foreach (['A', 'B', 'C'] as $i => $title) {
            Track::create([
                'album_id' => $album->id,
                'title' => $title,
                'object_key' => 'audio/'.$title.'.mp3',
                'sort_order' => $i + 1,
                'status' => 1,
            ]);
        }

        $c = Track::where('title', 'C')->firstOrFail();
        $b = Track::where('title', 'B')->firstOrFail();

        app(ReorderService::class)->tracks($album->id, $c->id, $b->id);

        $this->assertSame(
            ['A', 'C', 'B'],
            Track::where('album_id', $album->id)->orderBy('sort_order')->pluck('title')->all()
        );
    }

    public function test_history_report_upserts_and_bumps_version(): void
    {
        $album = Album::create(['title' => '测试专辑', 'sort_order' => 1, 'status' => 1]);
        $track = Track::create([
            'album_id' => $album->id, 'title' => '第一集', 'object_key' => 'audio/a.mp3',
            'sort_order' => 1, 'status' => 1, 'duration_sec' => 600,
        ]);
        $user = WxUser::create(['openid' => 'openid-test-001']);
        Sanctum::actingAs($user, ['*'], 'wx');

        $before = CatalogService::version();

        $this->postJson('/api/v1/me/histories', ['track_id' => $track->id, 'progress_sec' => 30, 'duration_sec' => 600])->assertOk();
        $this->postJson('/api/v1/me/histories', ['track_id' => $track->id, 'progress_sec' => 120, 'duration_sec' => 600])->assertOk();

        $this->assertSame(1, PlayHistory::where('wx_user_id', $user->id)->count());
        $this->assertSame(120, (int) PlayHistory::where('wx_user_id', $user->id)->value('progress_sec'));

        CatalogService::bump();
        $this->assertSame($before + 1, CatalogService::version());
    }
}
