<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Album;
use App\Models\PlayHistory;
use App\Models\Track;
use App\Models\WxUser;
use App\Services\AudioStorageService;
use App\Services\CatalogService;
use App\Services\ReorderService;
use App\Support\CoverUrl;
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

    public function test_reorder_moves_track_to_end_when_target_is_null(): void
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

        // 后台拖到末行下半部分时 target_id 传 null，语义为「移到末尾」
        app(ReorderService::class)->tracks($album->id, Track::where('title', 'A')->firstOrFail()->id, null);

        $this->assertSame(
            ['B', 'C', 'A'],
            Track::where('album_id', $album->id)->orderBy('sort_order')->pluck('title')->all()
        );
    }

    public function test_reorder_falls_back_to_end_when_target_row_is_gone(): void
    {
        [$album, $other] = $this->makeTwoAlbumsWithTracks();

        // 目标行已不在该专辑（例如页面打开后数据被改动），不应报 422，而是退化为移到末尾
        $staleTarget = Track::where('album_id', $other->id)->firstOrFail();
        $a = Track::where('album_id', $album->id)->where('title', 'A')->firstOrFail();

        $this->withSession(['admin_auth' => true])
            ->postJson('/admin/tracks/reorder', [
                'album_id' => $album->id,
                'track_id' => $a->id,
                'target_id' => $staleTarget->id,
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('degraded', true);

        $this->assertSame(
            ['B', 'C', 'A'],
            Track::where('album_id', $album->id)->orderBy('sort_order')->pluck('title')->all()
        );
    }

    public function test_reorder_uses_current_album_when_stale_album_id_given(): void
    {
        [$album, $other] = $this->makeTwoAlbumsWithTracks();

        // 前端传来的 album_id 已过期（track 实际仍在 $album），应以数据库为准而不是报错
        $c = Track::where('album_id', $album->id)->where('title', 'C')->firstOrFail();
        $b = Track::where('album_id', $album->id)->where('title', 'B')->firstOrFail();

        $this->withSession(['admin_auth' => true])
            ->postJson('/admin/tracks/reorder', [
                'album_id' => $other->id,
                'track_id' => $c->id,
                'target_id' => $b->id,
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonMissingPath('degraded');

        $this->assertSame(
            ['A', 'C', 'B'],
            Track::where('album_id', $album->id)->orderBy('sort_order')->pluck('title')->all()
        );
    }

    /** @return array{0: Album, 1: Album} 前者 3 条 A/B/C，后者 1 条 D */
    private function makeTwoAlbumsWithTracks(): array
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

        $other = Album::create(['title' => '另一个专辑', 'sort_order' => 2, 'status' => 1]);
        Track::create([
            'album_id' => $other->id, 'title' => 'D', 'object_key' => 'audio/D.mp3',
            'sort_order' => 1, 'status' => 1,
        ]);

        return [$album, $other];
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

    public function test_upload_page_has_no_runtime_diagnostics(): void
    {
        $this->withSession(['admin_auth' => true])
            ->get('/admin/tracks')
            ->assertOk()
            ->assertSee('上传并上架')
            ->assertDontSee('本次 Web 请求实测')
            ->assertDontSee('加载的 php.ini')
            ->assertDontSee('upload_max_filesize');
    }

    public function test_bootstrap_ships_content_level_names(): void
    {
        $this->getJson('/api/v1/bootstrap')
            ->assertOk()
            ->assertJsonPath('data.content.level_names.group', '专辑')
            ->assertJsonPath('data.content.level_names.item', '音频')
            ->assertJsonPath('data.content.max_depth', 2);
    }

    public function test_content_level_names_are_configurable_and_reach_admin_views(): void
    {
        // 只换「人看到的字」：接口路径与字段名不受影响
        config([
            'content.level_names.group' => '课程',
            'content.level_names.item' => '章节',
            'content.max_depth' => 3,
        ]);

        $this->getJson('/api/v1/bootstrap')
            ->assertOk()
            ->assertJsonPath('data.content.level_names.group', '课程')
            ->assertJsonPath('data.content.level_names.item', '章节')
            ->assertJsonPath('data.content.max_depth', 3);

        $this->withSession(['admin_auth' => true])
            ->get('/admin/albums')
            ->assertOk()
            ->assertSee('课程管理')
            ->assertSee('上架章节')
            ->assertDontSee('专辑管理');

        $this->withSession(['admin_auth' => true])
            ->get('/admin/tracks')
            ->assertOk()
            ->assertSee('章节管理')
            ->assertSee('所属课程')
            ->assertDontSee('音频管理');

        // 契约不变：路由与表字段仍是 albums / tracks
        $this->getJson('/api/v1/albums')->assertOk();
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

        // 下架后小程序「枕书」立即不可见（catalog 版本号已 bump）
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
        $this->assertStringStartsWith('cover/'.$album->id.'/', (string) $album->fresh()->cover_key);
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
        $this->assertStringStartsWith('cover/'.$album->id.'/', (string) $track->cover_key);
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
            ->assertSee('edit-album-'.$album->id, false)
            ->assertSee('action="'.route('admin.albums.destroy', $album).'"', false)
            ->assertSee('其下所有音频及桶内文件会一并删除', false);

        $this->withSession(['admin_auth' => true])
            ->get('/admin/tracks')
            ->assertOk()
            ->assertSee('封面（留空表示不修改）')
            ->assertSee('edit-track-', false);
    }

    public function test_admin_album_list_counts_only_published_tracks(): void
    {
        $album = Album::create(['title' => '口径测试', 'sort_order' => 1, 'status' => 1]);
        Track::create([
            'album_id' => $album->id, 'title' => '已上架', 'object_key' => 'audio/a.mp3',
            'sort_order' => 1, 'status' => 1,
        ]);
        Track::create([
            'album_id' => $album->id, 'title' => '草稿', 'object_key' => 'audio/b.mp3',
            'sort_order' => 2, 'status' => 0,
        ]);

        $html = $this->withSession(['admin_auth' => true])->get('/admin/albums')->assertOk()->getContent();

        $matched = preg_match('/<tr draggable="true" data-id="'.$album->id.'">(.*?)<\/tr>/s', $html, $row);
        $this->assertSame(1, $matched, '未找到专辑行');

        preg_match_all('/<td[^>]*>(.*?)<\/td>/s', $row[1], $cells);
        // 列序：顺序 / 封面 / 名称 / 上架音频 / 操作
        $this->assertCount(5, $cells[1]);
        $this->assertSame('1', trim(strip_tags($cells[1][3])), '音频数应只统计已上架，与接口 track_count 同口径');
    }

    public function test_cover_url_follows_cdn_domain_without_db_changes(): void
    {
        config(['services.cos.cdn_domain' => 'https://old-bucket.example.com']);

        $album = Album::create([
            'title' => '测试专辑', 'cover_key' => 'cover/1/a.png',
            'sort_order' => 1, 'status' => 1,
        ]);
        $track = Track::create([
            'album_id' => $album->id, 'title' => '第一集', 'object_key' => 'audio/a.mp3',
            'cover_key' => 'cover/1/b.png', 'sort_order' => 1, 'status' => 1,
        ]);

        $this->assertSame('https://old-bucket.example.com/cover/1/a.png', $album->cover_url);
        $this->assertSame('https://old-bucket.example.com/cover/1/b.png', $track->cover_url);

        // 只改配置（等价于换 .env 换桶），不刷库：模型与 API 输出立即切到新桶
        config(['services.cos.cdn_domain' => 'https://new-bucket.example.com']);
        Cache::flush();

        $this->assertSame('https://new-bucket.example.com/cover/1/a.png', $album->fresh()->cover_url);
        $this->getJson('/api/v1/albums')
            ->assertOk()
            ->assertJsonPath('data.list.0.cover_url', 'https://new-bucket.example.com/cover/1/a.png');
        $this->getJson('/api/v1/albums/'.$album->id.'/tracks')
            ->assertOk()
            ->assertJsonPath('data.list.0.cover_url', 'https://new-bucket.example.com/cover/1/b.png');
    }

    public function test_legacy_cover_url_without_key_is_returned_as_is(): void
    {
        config(['services.cos.cdn_domain' => 'https://new-bucket.example.com']);

        $album = Album::create([
            'title' => '旧数据',
            'cover_url' => 'https://legacy.example.com/cover/9/old.png',
            'sort_order' => 1, 'status' => 1,
        ]);

        $this->assertNull($album->fresh()->cover_key);
        $this->assertSame('https://legacy.example.com/cover/9/old.png', $album->fresh()->cover_url);
    }

    public function test_backfill_derives_cover_key_from_legacy_url(): void
    {
        config([
            'services.cos.cdn_domain' => 'https://new-bucket.example.com',
            'filesystems.disks.cos.bucket' => 'listen-nanjing-1302593534',
        ]);

        $album = Album::create([
            'title' => '旧专辑',
            'cover_url' => 'https://listen-nanjing-1302593534.cos.ap-nanjing.myqcloud.com/cover/3/01M6V9.png',
            'sort_order' => 1, 'status' => 1,
        ]);
        $track = Track::create([
            'album_id' => $album->id, 'title' => '旧音频', 'object_key' => 'audio/a.mp3',
            'cover_url' => 'cover/3/01M6V8.png', // 已是相对 key 的历史值
            'sort_order' => 1, 'status' => 1,
        ]);

        $this->assertSame(2, CoverUrl::backfill('albums') + CoverUrl::backfill('tracks'));

        $this->assertSame('cover/3/01M6V9.png', $album->fresh()->cover_key);
        $this->assertSame('cover/3/01M6V8.png', $track->fresh()->cover_key);
        $this->assertSame('https://new-bucket.example.com/cover/3/01M6V9.png', $album->fresh()->cover_url);

        // path-style 老 URL 与空值边界
        $this->assertSame('cover/3/x.png', CoverUrl::keyFrom('https://cos.ap-nanjing.myqcloud.com/listen-nanjing-1302593534/cover/3/x.png'));
        $this->assertNull(CoverUrl::keyFrom(null));
        $this->assertNull(CoverUrl::keyFrom('https://example.com'));
    }

    public function test_history_returns_cover_url_from_cover_key(): void
    {
        config(['services.cos.cdn_domain' => 'https://new-bucket.example.com']);

        $album = Album::create(['title' => '测试专辑', 'sort_order' => 1, 'status' => 1]);
        $track = Track::create([
            'album_id' => $album->id, 'title' => '第一集', 'object_key' => 'audio/a.mp3',
            'cover_key' => 'cover/1/h.png', 'sort_order' => 1, 'status' => 1, 'duration_sec' => 600,
        ]);
        $user = WxUser::create(['openid' => 'openid-cover-test']);
        Sanctum::actingAs($user, ['*'], 'wx');

        $this->postJson('/api/v1/me/histories', [
            'track_id' => $track->id, 'progress_sec' => 30, 'duration_sec' => 600,
        ])->assertOk();

        // HistoryController 是显式 select，漏选 cover_key 会导致这里退回旧 URL / null
        $this->getJson('/api/v1/me/histories')
            ->assertOk()
            ->assertJsonPath('data.list.0.track.cover_url', 'https://new-bucket.example.com/cover/1/h.png');
    }

    public function test_cos_upload_sends_no_object_acl(): void
    {
        config([
            'filesystems.disks.cos.driver'   => 's3',
            'filesystems.disks.cos.key'      => 'probe',
            'filesystems.disks.cos.secret'   => 'probe',
            'filesystems.disks.cos.bucket'   => 'listen-book-1250000000',
            'filesystems.disks.cos.endpoint' => 'https://cos.ap-guangzhou.myqcloud.com',
            'filesystems.disks.cos.url'      => 'https://cdn.example.com',
        ]);

        $client = \Mockery::mock(\Aws\S3\S3Client::class);
        $client->shouldReceive('upload')
            ->once()
            ->with(
                'listen-book-1250000000',
                \Mockery::pattern('#^audio/1/[A-Z0-9]+\.mp3$#'),
                \Mockery::type('resource'),
                null, // 不发 x-amz-acl：对象继承桶的公有读，避免被显式 private 覆盖
                \Mockery::on(fn ($options) => ($options['params']['ContentType'] ?? null) === 'audio/mpeg')
            )
            ->andReturn(new \Aws\Result([]));

        $disk = \Mockery::mock(\Illuminate\Filesystem\AwsS3V3Adapter::class);
        $disk->shouldReceive('getClient')->once()->andReturn($client);
        Storage::shouldReceive('disk')->with('cos')->andReturn($disk);

        $tmp = tempnam(sys_get_temp_dir(), 'lb_');
        file_put_contents($tmp, 'ID3'.str_repeat("\0", 64));
        $file = new \Illuminate\Http\UploadedFile($tmp, 'a.mp3', 'audio/mpeg', null, true);

        try {
            $result = app(AudioStorageService::class)->storeAudio($file, 1);
            $this->assertStringStartsWith('audio/1/', $result['object_key']);
            $this->assertSame('audio/mpeg', $result['mime']);
        } finally {
            @unlink($tmp);
        }
    }

    public function test_destroy_track_deletes_cos_objects(): void
    {
        Storage::fake('cos');
        config([
            'filesystems.disks.cos.driver' => 'local',
            'services.cos.cdn_domain' => 'https://cdn.example.com',
        ]);

        $album = Album::create(['title' => '测试专辑', 'sort_order' => 1, 'status' => 1]);
        $track = Track::create([
            'album_id' => $album->id, 'title' => '第一集',
            'object_key' => 'audio/1/a.mp3', 'cover_key' => 'cover/1/a.png',
            'sort_order' => 1, 'status' => 1,
        ]);
        Storage::disk('cos')->put('audio/1/a.mp3', 'audio');
        Storage::disk('cos')->put('cover/1/a.png', 'cover');

        $this->withSession(['admin_auth' => true])
            ->delete('/admin/tracks/'.$track->id)
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        // 软删保留 DB 记录，桶对象同步清掉
        $this->assertNotNull(Track::withTrashed()->find($track->id)->deleted_at);
        Storage::disk('cos')->assertMissing('audio/1/a.mp3');
        Storage::disk('cos')->assertMissing('cover/1/a.png');
    }

    public function test_destroy_album_cascades_tracks_and_cos_objects(): void
    {
        Storage::fake('cos');
        config([
            'filesystems.disks.cos.driver' => 'local',
            'services.cos.cdn_domain' => 'https://cdn.example.com',
        ]);

        $album = Album::create([
            'title' => '测试专辑', 'cover_key' => 'cover/9/album.png',
            'sort_order' => 1, 'status' => 1,
        ]);
        $track = Track::create([
            'album_id' => $album->id, 'title' => '第一集',
            'object_key' => 'audio/9/1.mp3', 'cover_key' => 'cover/9/1.png',
            'sort_order' => 1, 'status' => 1,
        ]);
        // 历史数据：封面只存完整 URL，删除时要能反推出 key
        $legacy = Track::create([
            'album_id' => $album->id, 'title' => '第二集',
            'object_key' => 'audio/9/2.mp3',
            'cover_url' => 'https://legacy.example.com/cover/9/2.png',
            'sort_order' => 2, 'status' => 1,
        ]);
        // 之前已软删但桶对象没清的，也要一并清掉
        $old = Track::create([
            'album_id' => $album->id, 'title' => '旧集',
            'object_key' => 'audio/9/old.mp3', 'sort_order' => 3, 'status' => 0,
        ]);
        $old->delete();

        foreach (['cover/9/album.png', 'audio/9/1.mp3', 'cover/9/1.png', 'audio/9/2.mp3', 'cover/9/2.png', 'audio/9/old.mp3'] as $key) {
            Storage::disk('cos')->put($key, 'x');
        }

        $this->withSession(['admin_auth' => true])
            ->delete('/admin/albums/'.$album->id)
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertNotNull(Album::withTrashed()->find($album->id)->deleted_at);
        foreach ([$track, $legacy, $old] as $item) {
            $this->assertNotNull(Track::withTrashed()->find($item->id)->deleted_at);
        }
        foreach (['cover/9/album.png', 'audio/9/1.mp3', 'cover/9/1.png', 'audio/9/2.mp3', 'cover/9/2.png', 'audio/9/old.mp3'] as $key) {
            Storage::disk('cos')->assertMissing($key);
        }
        $this->getJson('/api/v1/albums')->assertOk()->assertJsonCount(0, 'data.list');
    }

    public function test_delete_many_normalizes_keys_and_skips_failures(): void
    {
        Storage::fake('cos');
        Storage::disk('cos')->put('audio/1/a.mp3', 'x');

        $deleted = app(AudioStorageService::class)->deleteMany([
            'https://legacy.example.com/audio/1/a.mp3', // 完整 URL → 相对 key
            'audio/1/a.mp3',                            // 同一个 key，去重
            null,
            '',
            'https://example.com',                      // 无 path，忽略
        ]);

        $this->assertSame(1, $deleted);
        Storage::disk('cos')->assertMissing('audio/1/a.mp3');
    }
}
