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
use Illuminate\Support\Facades\Storage;
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

    public function test_upload_failure_message_reports_php_limits(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'lb_');
        file_put_contents($tmp, 'x');

        $file = new \Illuminate\Http\UploadedFile($tmp, 'a.mp3', 'audio/mpeg', UPLOAD_ERR_INI_SIZE, true);
        $request = \App\Http\Requests\StoreTrackRequest::create('/admin/tracks', 'POST', [], [], ['audio' => $file]);
        $request->setContainer($this->app);

        $validator = $this->app->make('validator')->make(
            $request->all(),
            ['audio' => ['required', 'file', 'max:102400']],
            $request->messages()
        );

        $message = $validator->errors()->first('audio');
        $this->assertStringContainsString('upload_max_filesize', $message);
        $this->assertStringContainsString((string) ini_get('upload_max_filesize'), $message);
        $this->assertStringContainsString('reload php-fpm', $message);
        $this->assertStringContainsString('SAPI=', $message);
        $this->assertStringContainsString('实际加载的 php.ini', $message);
        $this->assertStringContainsString(php_ini_loaded_file() ?: '未加载 php.ini（使用编译默认值）', $message);

        @unlink($tmp);
    }

    public function test_upload_page_reports_web_runtime_ini(): void
    {
        $this->withSession(['admin_auth' => true])
            ->get('/admin/tracks')
            ->assertOk()
            ->assertSee('本次 Web 请求实测')
            ->assertSee('加载的 php.ini')
            ->assertSee((string) ini_get('upload_max_filesize'));
    }

    public function test_admin_can_update_album_and_unpublish_it(): void
    {
        $album = Album::create(['title' => '旧名称', 'description' => '旧简介', 'sort_order' => 1, 'status' => 1]);

        $this->withSession(['admin_auth' => true])
            ->patch('/admin/albums/'.$album->id, [
                'title' => '新名称',
                'description' => '新简介',
                'status' => '0',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertDatabaseHas('albums', [
            'id' => $album->id,
            'title' => '新名称',
            'description' => '新简介',
            'status' => 0,
        ]);

        // 下架后小程序书库立即不可见（catalog 版本号已 bump）
        $this->getJson('/api/v1/albums')
            ->assertOk()
            ->assertJsonCount(0, 'data.list');
    }

    public function test_admin_can_replace_album_cover(): void
    {
        Storage::fake('cos');
        // fake 只替换磁盘实例，不改 config；需同步把 driver 标成 local，跳过 s3 密钥自检
        config([
            'filesystems.disks.cos.driver' => 'local',
            'services.cos.cdn_domain' => 'https://cdn.example.com',
        ]);

        $album = Album::create(['title' => '测试专辑', 'sort_order' => 1, 'status' => 1]);
        $tmp = tempnam(sys_get_temp_dir(), 'lb_');
        file_put_contents($tmp, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
        $cover = new \Illuminate\Http\UploadedFile($tmp, 'cover.png', 'image/png', null, true);

        $this->withSession(['admin_auth' => true])
            ->patch('/admin/albums/'.$album->id, ['cover' => $cover])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $url = (string) $album->fresh()->cover_url;
        $this->assertStringStartsWith('https://cdn.example.com/cover/'.$album->id.'/', $url);
        Storage::disk('cos')->assertExists(ltrim((string) parse_url($url, PHP_URL_PATH), '/'));

        @unlink($tmp);
    }

    public function test_admin_can_update_track_title_and_cover(): void
    {
        Storage::fake('cos');
        // fake 只替换磁盘实例，不改 config；需同步把 driver 标成 local，跳过 s3 密钥自检
        config([
            'filesystems.disks.cos.driver' => 'local',
            'services.cos.cdn_domain' => 'https://cdn.example.com',
        ]);

        $album = Album::create(['title' => '测试专辑', 'sort_order' => 1, 'status' => 1]);
        $track = Track::create([
            'album_id' => $album->id, 'title' => '旧标题', 'object_key' => 'audio/a.mp3',
            'sort_order' => 1, 'status' => 1,
        ]);
        $tmp = tempnam(sys_get_temp_dir(), 'lb_');
        file_put_contents($tmp, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
        $cover = new \Illuminate\Http\UploadedFile($tmp, 'cover.png', 'image/png', null, true);

        $this->withSession(['admin_auth' => true])
            ->patch('/admin/tracks/'.$track->id, ['title' => '新标题', 'cover' => $cover])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $track->refresh();
        $this->assertSame('新标题', $track->title);
        $this->assertStringStartsWith('https://cdn.example.com/cover/'.$album->id.'/', (string) $track->cover_url);
        $this->assertSame('audio/a.mp3', $track->object_key); // 只改标题/封面，不动音频源

        @unlink($tmp);
    }

    public function test_admin_pages_expose_edit_forms(): void
    {
        $album = Album::create(['title' => '测试专辑', 'sort_order' => 1, 'status' => 1]);
        Track::create([
            'album_id' => $album->id, 'title' => '第一集', 'object_key' => 'audio/a.mp3',
            'sort_order' => 1, 'status' => 1,
        ]);

        $this->withSession(['admin_auth' => true])
            ->get('/admin/albums')
            ->assertOk()
            ->assertSee('name="description"', false)
            ->assertSee('name="status"', false)
            ->assertSee('edit-album-'.$album->id, false);

        $this->withSession(['admin_auth' => true])
            ->get('/admin/tracks')
            ->assertOk()
            ->assertSee('封面（留空表示不修改）')
            ->assertSee('edit-track-', false);
    }
}
