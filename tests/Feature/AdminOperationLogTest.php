<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AdminOperationLog;
use App\Models\Album;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class AdminOperationLogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function makeAdmin(array $overrides = []): Admin
    {
        return Admin::create(array_merge([
            'username' => 'boss',
            'phone' => '13800000001',
            'password' => 'test1234',
            'role' => Admin::ROLE_SUPER,
            'status' => 1,
        ], $overrides));
    }

    private function tokenFor(Admin $admin): string
    {
        $token = (string) JWTAuth::fromUser($admin);

        // guard / tymon.jwt 是单例，签发后必须清掉缓存才能让中间件从请求头重新解析
        $this->app['auth']->forgetGuards();
        $this->app['tymon.jwt']->unsetToken();

        return $token;
    }

    public function test_login_success_and_failure_are_both_logged(): void
    {
        $admin = $this->makeAdmin();

        $this->postJson('/api/admin/auth/login', [
            'account' => 'boss',
            'password' => 'test1234',
        ])->assertOk();

        $this->postJson('/api/admin/auth/login', [
            'account' => 'boss',
            'password' => 'wrongpass1',
        ])->assertStatus(401);

        $this->assertSame(2, AdminOperationLog::query()->where('action', 'auth.login')->count());

        $ok = AdminOperationLog::query()->where('action', 'auth.login')->where('success', true)->first();
        $this->assertSame($admin->id, $ok->admin_id);
        $this->assertSame('boss', $ok->username);
        $this->assertSame('登录', $ok->summary);
        $this->assertSame(200, $ok->status);

        $failed = AdminOperationLog::query()->where('action', 'auth.login')->where('success', false)->first();
        $this->assertSame('boss', $failed->username);
        $this->assertStringContainsString('失败', $failed->summary);
    }

    public function test_write_operations_are_logged_but_reads_are_not(): void
    {
        $token = $this->tokenFor($this->makeAdmin());

        $this->withToken($token)->getJson('/api/admin/albums')->assertOk();
        $this->assertSame(0, AdminOperationLog::query()->count());

        $this->withToken($token)->postJson('/api/admin/albums', ['title' => '新专辑'])->assertOk();

        $log = AdminOperationLog::query()->sole();
        $this->assertSame('albums.store', $log->action);
        $this->assertSame('新建专辑「新专辑」', $log->summary);
        $this->assertSame('/api/admin/albums', $log->path);
        $this->assertSame('POST', $log->method);
        $this->assertSame('127.0.0.1', $log->ip);
        $this->assertSame('新专辑', $log->payload['title']);
    }

    public function test_refresh_is_not_logged(): void
    {
        $this->makeAdmin();
        $data = $this->postJson('/api/admin/auth/login', [
            'account' => 'boss',
            'password' => 'test1234',
        ])->assertOk()->json('data');

        $this->postJson('/api/admin/auth/refresh', [
            'refresh_token' => $data['refresh_token'],
        ])->assertOk();

        $this->assertSame(1, AdminOperationLog::query()->count());
        $this->assertSame('auth.login', AdminOperationLog::query()->sole()->action);
    }

    public function test_passwords_and_tokens_never_land_in_log_payload(): void
    {
        $token = $this->tokenFor($this->makeAdmin());

        $this->withToken($token)->postJson('/api/admin/auth/password', [
            'old_password' => 'test1234',
            'password' => 'newpass123',
            'password_confirmation' => 'newpass123',
        ])->assertOk();

        $this->withToken($token)->postJson('/api/admin/auth/logout', [
            'refresh_token' => 'some-refresh-token',
        ])->assertOk();

        $dump = AdminOperationLog::query()->get()->map(fn ($log) => json_encode($log->payload, JSON_UNESCAPED_UNICODE))->implode("\n");
        $this->assertStringNotContainsString('test1234', $dump);
        $this->assertStringNotContainsString('newpass123', $dump);
        $this->assertStringNotContainsString('some-refresh-token', $dump);

        $passwordLog = AdminOperationLog::query()->where('action', 'auth.password')->sole();
        $this->assertArrayNotHasKey('password', $passwordLog->payload ?? []);
        $this->assertArrayNotHasKey('old_password', $passwordLog->payload ?? []);
    }

    public function test_delete_logs_target_and_preserves_username_after_account_removal(): void
    {
        $boss = $this->makeAdmin();
        $staff = $this->makeAdmin([
            'username' => 'staff',
            'phone' => '13800000002',
            'role' => Admin::ROLE_ADMIN,
        ]);
        $album = Album::create(['title' => '待删专辑', 'sort_order' => 1, 'status' => 1]);
        $token = $this->tokenFor($boss);

        $this->withToken($token)
            ->withHeader('User-Agent', 'LogTest/1.0')
            ->deleteJson('/api/admin/albums/'.$album->id)
            ->assertOk();

        $log = AdminOperationLog::query()->where('action', 'albums.destroy')->sole();
        $this->assertSame('album', $log->target_type);
        $this->assertSame($album->id, $log->target_id);
        $this->assertSame('LogTest/1.0', $log->user_agent);
        $this->assertSame($boss->id, $log->admin_id);

        // 账号移除后日志仍可读出当时的用户名快照
        $this->withToken($token)->deleteJson('/api/admin/admins/'.$staff->id)->assertOk();
        $staff->forceDelete();
        $this->assertSame('boss', AdminOperationLog::query()->find($log->id)->username);
    }

    public function test_only_super_can_read_logs(): void
    {
        $super = $this->makeAdmin();
        $admin = $this->makeAdmin([
            'username' => 'staff',
            'phone' => '13800000002',
            'role' => Admin::ROLE_ADMIN,
        ]);

        $this->withToken($this->tokenFor($admin))->getJson('/api/admin/logs')
            ->assertStatus(403)
            ->assertJsonPath('code', 40302);

        $this->withToken($this->tokenFor($super))->getJson('/api/admin/logs')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonStructure(['data' => ['items', 'pagination', 'filters' => ['actions', 'admins']]]);
    }

    public function test_log_filters_by_action_and_keyword(): void
    {
        $admin = $this->makeAdmin();
        AdminOperationLog::query()->create([
            'admin_id' => $admin->id, 'username' => 'boss', 'action' => 'albums.store',
            'summary' => '新建专辑「甲」', 'method' => 'POST', 'path' => '/api/admin/albums',
            'status' => 200, 'success' => true, 'created_at' => now(),
        ]);
        AdminOperationLog::query()->create([
            'admin_id' => $admin->id, 'username' => 'boss', 'action' => 'tracks.destroy',
            'summary' => '删除音频 #7', 'method' => 'DELETE', 'path' => '/api/admin/tracks/7',
            'status' => 200, 'success' => false, 'created_at' => now(),
        ]);

        $token = $this->tokenFor($admin);

        $this->withToken($token)->getJson('/api/admin/logs?action=tracks.destroy')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.action_label', '删除音频');

        $this->withToken($token)->getJson('/api/admin/logs?keyword='.urlencode('新建专辑'))
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.action', 'albums.store');

        $this->withToken($token)->getJson('/api/admin/logs?success=0')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.success', false);
    }

    public function test_log_page_renders_readonly_skeleton(): void
    {
        $this->get('/admin/logs')
            ->assertOk()
            ->assertSee('操作日志')
            ->assertSee('筛选')
            ->assertSee('data-super-only', false)
            ->assertDontSee('id="log-delete"', false);
    }

    public function test_restricted_pages_are_gated_before_body_renders(): void
    {
        // 受限页由服务端打标记，<head> 守卫据此在 body 渲染前隐藏主体，
        // 普通管理员直接敲 URL 不会闪出「创建 / 重置密码 / 移除」按钮。
        foreach (['/admin/admins', '/admin/logs'] as $path) {
            $html = $this->get($path)->assertOk()->getContent();
            $this->assertMatchesRegularExpression('/<html[^>]*\bdata-lb-role-gate\b/', $html, $path.' 未打角色门禁标记');
            $this->assertStringContainsString('[data-lb-role-gate]:not(.lb-role-ready) main', $html, $path.' 缺少默认隐藏主体的样式');
            $this->assertStringContainsString(':not(.lb-super-ready) [data-super-only]', $html, $path.' 缺少默认隐藏 super 入口的样式');
        }

        foreach (['/admin/tracks', '/admin/albums'] as $path) {
            $html = $this->get($path)->assertOk()->getContent();
            $this->assertDoesNotMatchRegularExpression('/<html[^>]*\bdata-lb-role-gate\b/', $html, $path.' 不应被角色门禁影响');
        }

        // 每个页面加载都会用 me 校准本地 profile 快照，且引用了新版本脚本
        $html = $this->get('/admin/tracks')->getContent();
        $this->assertStringContainsString('syncProfile', $html);
        $this->assertStringContainsString('js/admin.js?v=3', $html);
    }

    public function test_me_returns_current_role_from_database(): void
    {
        $admin = $this->makeAdmin([
            'username' => 'staff',
            'phone' => '13900000009',
            'role' => Admin::ROLE_ADMIN,
        ]);
        $token = $this->tokenFor($admin);

        $this->withToken($token)->getJson('/api/admin/auth/me')
            ->assertOk()
            ->assertJsonPath('data.role', Admin::ROLE_ADMIN);

        // 角色被别的超级管理员改动后，前端下一次 me 就能拿到新值，无需重新登录
        $admin->forceFill(['role' => Admin::ROLE_SUPER])->save();
        $this->app['auth']->forgetGuards();
        $this->app['tymon.jwt']->unsetToken();

        $this->withToken($token)->getJson('/api/admin/auth/me')
            ->assertOk()
            ->assertJsonPath('data.role', Admin::ROLE_SUPER);
    }
}
