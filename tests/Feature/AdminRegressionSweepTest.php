<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Album;
use App\Models\Track;
use App\Services\CatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * 后台功能巡检：覆盖 v0.15 之后几轮 UI / 上传 / 换专辑改动里容易回归的路径。
 * 与既有用例互补，不重复验证已覆盖的业务口径。
 */
class AdminRegressionSweepTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // 上传走 Storage::disk('cos')，测试里用 local 假盘，绝不碰真实 COS
        Storage::fake('cos');
        config(['filesystems.disks.cos.driver' => 'local']);
    }

    private function asAdmin(string $role = Admin::ROLE_SUPER): static
    {
        $admin = Admin::create([
            'username' => 'admin_'.Str::random(6),
            'phone' => '138'.random_int(10000000, 99999999),
            'password' => 'secret123',
            'role' => $role,
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

    /** ID3 头的假 mp3，能过 AudioStorageService 的签名校验。 */
    private function fakeMp3(string $name = 'track.mp3'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            $name,
            "ID3\x04\x00\x00\x00\x00\x00\x00".str_repeat("\x00", 256)
        );
    }

    private function fakePng(string $name = 'cover.png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            $name,
            (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==')
        );
    }

    public function test_create_buttons_live_in_operation_column_and_modals_stay_closed(): void
    {
        $this->asAdmin();

        $pages = [
            '/admin/albums' => ['open-create', 'create-dialog', 5],
            '/admin/tracks' => ['open-upload', 'upload-dialog', 7],
            '/admin/admins' => ['open-create', 'create-dialog', 6],
        ];

        foreach ($pages as $url => [$buttonId, $dialogId, $columns]) {
            $html = (string) $this->get($url)->assertOk()->getContent();

            $thead = strpos($html, '<thead');
            $theadEnd = strpos($html, '</thead>');
            $button = strpos($html, 'id="'.$buttonId.'"');

            $this->assertNotFalse($thead, $url.' 缺少表头');
            $this->assertNotFalse($theadEnd, $url.' 表头未闭合');
            $this->assertNotFalse($button, $url.' 缺少创建按钮');
            $this->assertGreaterThan($thead, $button, $url.' 创建按钮不在表头内');
            $this->assertLessThan($theadEnd, $button, $url.' 创建按钮不在「操作」列内');

            $this->assertStringContainsString('class="th-actions"', $html, $url.' 缺少表头操作栏');
            $this->assertStringContainsString('.btn-primary.btn-compact', $html, $url.' 缺少紧凑按钮样式');
            $this->assertStringNotContainsString('class="page-actions"', $html, $url.' 仍有独立的页面操作区');
            $this->assertStringContainsString('id="'.$dialogId.'"', $html, $url.' 缺少弹窗');
            $this->assertStringContainsString('js/admin.js?v=4', $html, $url.' 脚本版本未更新');
            $this->assertDoesNotMatchRegularExpression('/<dialog[^>]*\sopen[\s>]/', $html, $url.' 弹窗默认应为关闭');

            preg_match_all('/<th(?:\s|>)/', $html, $matches);
            $this->assertCount($columns, $matches[0], $url.' 表头列数变化');
        }
    }

    public function test_topbar_navigation_order_is_stable(): void
    {
        $this->asAdmin();

        $html = (string) $this->get('/admin/tracks')->assertOk()->getContent();

        $order = [
            'albums' => route('admin.albums.index'),
            'tracks' => route('admin.tracks.index'),
            'admins' => route('admin.admins.index'),
            'stats' => route('admin.stats.index'),
            'logs' => route('admin.logs.index'),
        ];

        $previous = -1;
        foreach ($order as $name => $url) {
            $position = strpos($html, $url);
            $this->assertNotFalse($position, $name.' 导航入口缺失');
            $this->assertGreaterThan($previous, $position, $name.' 导航顺序被打乱');
            $previous = $position;
        }

        // CSS 里也有 [data-super-only] 选择器，这里只数真正挂在链接上的属性
        $this->assertSame(2, substr_count($html, 'data-super-only>'), '受限入口应恰好两个');
        $this->assertStringContainsString('id="logout-btn"', $html);
        $this->assertStringContainsString('class="head-right"', $html);
    }

    public function test_batch_upload_creates_sequential_tracks_and_one_log_each(): void
    {
        $this->asAdmin();
        $album = $this->makeAlbum('上册');
        $before = CatalogService::version();

        foreach (['01.mp3', '02.mp3', '03.mp3'] as $name) {
            $this->post('/api/admin/tracks', [
                'album_id' => $album->id,
                'title' => pathinfo($name, PATHINFO_FILENAME),
                'audio' => $this->fakeMp3($name),
            ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('code', 0);
        }

        $tracks = Track::query()->orderBy('sort_order')->get();
        $this->assertCount(3, $tracks);
        $this->assertSame([1, 2, 3], array_map(fn (Track $t) => (int) $t->sort_order, $tracks->all()));
        $this->assertSame($before + 3, CatalogService::version(), '每个文件都应推进目录版本');

        foreach ($tracks as $track) {
            $this->assertSame($album->id, (int) $track->album_id);
            Storage::disk('cos')->assertExists($track->object_key);
        }

        $this->assertSame(
            3,
            DB::table('admin_operation_logs')->where('action', 'tracks.store')->count(),
            '批量上传按文件逐个落日志'
        );
    }

    public function test_upload_rejects_missing_album_unknown_album_and_bad_audio(): void
    {
        $this->asAdmin();
        $album = $this->makeAlbum('上册');

        $this->post('/api/admin/tracks', [
            'title' => '缺专辑',
            'audio' => $this->fakeMp3(),
        ], ['Accept' => 'application/json'])->assertStatus(422);

        $this->post('/api/admin/tracks', [
            'album_id' => 999999,
            'title' => '专辑不存在',
            'audio' => $this->fakeMp3(),
        ], ['Accept' => 'application/json'])->assertStatus(422);

        $this->post('/api/admin/tracks', [
            'album_id' => $album->id,
            'title' => '扩展名不支持',
            'audio' => $this->fakeMp3('track.txt'),
        ], ['Accept' => 'application/json'])->assertStatus(422);

        $this->post('/api/admin/tracks', [
            'album_id' => $album->id,
            'title' => '内容不是音频',
            'audio' => UploadedFile::fake()->createWithContent('track.mp3', 'plain text'),
        ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonPath('code', 42211);

        $this->assertSame(0, Track::query()->count(), '失败的请求不应留下记录');
        $this->assertSame([], Storage::disk('cos')->allFiles(), '失败的请求不应留下桶对象');
    }

    public function test_moving_track_to_its_own_album_keeps_sort_order(): void
    {
        $this->asAdmin();
        $album = $this->makeAlbum('上册');
        $first = $this->makeTrack($album, 'one', 1);
        $this->makeTrack($album, 'two', 2);
        $version = CatalogService::version();

        $this->patchJson('/api/admin/tracks/'.$first->id, ['album_id' => $album->id])
            ->assertOk()
            ->assertJsonPath('data.album_id', $album->id);

        $this->assertSame(1, (int) $first->fresh()->sort_order, '未换专辑不应重排顺序');
        $this->assertSame($version + 1, CatalogService::version());
    }

    public function test_moving_track_accepts_string_album_id_and_reassigns_cover_to_new_album(): void
    {
        $this->asAdmin();
        $from = $this->makeAlbum('上册');
        $to = $this->makeAlbum('下册');
        $track = $this->makeTrack($from, 'chapter-one', 1);

        $this->post('/api/admin/tracks/'.$track->id, [
            '_method' => 'PATCH',
            'album_id' => (string) $to->id,
            'cover' => $this->fakePng(),
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.album_id', $to->id);

        $track->refresh();
        $this->assertSame($to->id, (int) $track->album_id);
        $this->assertStringStartsWith('cover/'.$to->id.'/', (string) $track->cover_key, '封面应归到目标专辑目录');
        $this->assertSame('audio/'.$from->id.'/chapter-one.mp3', $track->object_key, '换专辑不搬音频对象');
    }

    public function test_stats_clamps_extreme_days_and_survives_empty_data(): void
    {
        $this->asAdmin();

        foreach ([0, -5, 1, 90, 3650, 999999] as $days) {
            $data = $this->getJson('/api/admin/stats?days='.$days)->assertOk()->json('data');

            $this->assertGreaterThanOrEqual(1, $data['days']);
            $this->assertLessThanOrEqual(90, $data['days']);
            $this->assertCount($data['days'], $data['trend']);
            $this->assertSame(0, $data['overview']['users']);
            $this->assertSame(0, $data['overview']['total_listen_sec']);
            $this->assertSame([], $data['albums']);
        }

        $this->getJson('/api/admin/stats?days=abc')->assertOk();
    }

    public function test_regular_admin_reads_stats_and_content_but_not_admin_management(): void
    {
        $this->asAdmin(Admin::ROLE_ADMIN);

        $this->getJson('/api/admin/stats')->assertOk();
        $this->getJson('/api/admin/tracks')->assertOk();
        $this->getJson('/api/admin/albums')->assertOk();

        $this->getJson('/api/admin/admins')
            ->assertStatus(403)
            ->assertJsonPath('code', 40302);
        $this->getJson('/api/admin/logs')->assertStatus(403);

        // 超级管理员专属的写操作同样被拦（不能靠前端隐藏当安全边界）
        $this->postJson('/api/admin/admins', [
            'username' => 'sneaky',
            'phone' => '13900000000',
            'password' => 'secret123',
            'role' => 'super',
        ])->assertStatus(403);
    }
}
