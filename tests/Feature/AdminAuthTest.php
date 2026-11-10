<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AdminRefreshToken;
use App\Services\AdminAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAuthTest extends TestCase
{
    use RefreshDatabase;

    private function makeAdmin(array $overrides = []): Admin
    {
        return Admin::create(array_merge([
            'username' => 'tester',
            'phone' => '13800000000',
            'password' => 'test1234',
            'role' => Admin::ROLE_SUPER,
            'status' => 1,
        ], $overrides));
    }

    /** @return array<string, mixed> 登录返回的 data 段 */
    private function loginTokens(string $account = 'tester', string $password = 'test1234'): array
    {
        $data = $this->postJson('/api/admin/auth/login', [
            'account' => $account,
            'password' => $password,
        ])->assertOk()->json('data');

        // 登录接口内部调用过 guard->login()，会在单例上缓存 token 与 user；
        // 不清掉的话后续请求会复用这份缓存，篡改 token 的用例会假通过
        $this->forgetAuthState();

        return $data;
    }

    private function forgetAuthState(): void
    {
        $this->app['auth']->forgetGuards();
        $this->app['tymon.jwt']->unsetToken();
    }

    public function test_login_with_username_returns_access_and_refresh_tokens(): void
    {
        $this->makeAdmin();

        $data = $this->loginTokens();

        $this->assertNotEmpty($data['access_token']);
        $this->assertNotEmpty($data['refresh_token']);
        $this->assertSame('Bearer', $data['token_type']);
        $this->assertSame(120 * 60, $data['expires_in']);
        $this->assertSame('tester', $data['admin']['username']);
        // refresh token 只存哈希，明文不出库
        $this->assertSame(1, AdminRefreshToken::query()->count());
        $this->assertNotSame($data['refresh_token'], AdminRefreshToken::query()->value('token_hash'));
    }

    public function test_login_accepts_phone_as_account(): void
    {
        $this->makeAdmin();

        $data = $this->loginTokens('13800000000');

        $this->assertSame('tester', $data['admin']['username']);
    }

    public function test_login_rejects_wrong_password(): void
    {
        $this->makeAdmin();

        $this->postJson('/api/admin/auth/login', ['account' => 'tester', 'password' => 'wrong-pass1'])
            ->assertStatus(401)
            ->assertJsonPath('code', AdminAuthService::CODE_UNAUTHENTICATED);
    }

    public function test_unknown_account_gets_the_same_message_as_wrong_password(): void
    {
        $this->makeAdmin();

        $wrongPassword = $this->postJson('/api/admin/auth/login', [
            'account' => 'tester', 'password' => 'wrong-pass1',
        ])->json('message');

        $unknownAccount = $this->postJson('/api/admin/auth/login', [
            'account' => 'nobody', 'password' => 'wrong-pass1',
        ])->assertStatus(401)->json('message');

        $this->assertSame($wrongPassword, $unknownAccount, '提示文案一致，避免枚举账号');
    }

    public function test_disabled_account_cannot_login(): void
    {
        $this->makeAdmin(['status' => 0]);

        $this->postJson('/api/admin/auth/login', ['account' => 'tester', 'password' => 'test1234'])
            ->assertStatus(403)
            ->assertJsonPath('code', AdminAuthService::CODE_DISABLED);
    }

    public function test_account_locks_after_five_failed_attempts(): void
    {
        $admin = $this->makeAdmin();

        for ($i = 1; $i <= 5; $i++) {
            $this->postJson('/api/admin/auth/login', ['account' => 'tester', 'password' => 'bad-pass'.$i])
                ->assertStatus(401);
        }

        $admin->refresh();
        $this->assertNotNull($admin->locked_until);
        $this->assertSame(5, $admin->failed_attempts);

        // 锁定期间即使密码正确也拒绝，且不再累加失败次数（防 DoS）
        $this->postJson('/api/admin/auth/login', ['account' => 'tester', 'password' => 'test1234'])
            ->assertStatus(429)
            ->assertJsonPath('code', AdminAuthService::CODE_LOCKED);

        $this->assertSame(5, $admin->fresh()->failed_attempts);
    }

    public function test_protected_endpoints_require_bearer_token(): void
    {
        $this->makeAdmin();

        foreach (['/api/admin/auth/me', '/api/admin/albums', '/api/admin/tracks', '/api/admin/admins'] as $url) {
            $this->getJson($url)
                ->assertStatus(401)
                ->assertJsonPath('code', AdminAuthService::CODE_UNAUTHENTICATED);
        }
    }

    public function test_tampered_token_is_rejected(): void
    {
        $this->makeAdmin();
        $token = $this->loginTokens()['access_token'];

        // 整段替换签名：只改末尾一位可能落在 base64url 的无关位上，解码后字节不变
        $parts = explode('.', $token);
        $parts[2] = str_repeat('A', strlen($parts[2]));
        $tampered = implode('.', $parts);

        $this->withToken($tampered)->getJson('/api/admin/auth/me')
            ->assertStatus(401)
            ->assertJsonPath('code', AdminAuthService::CODE_UNAUTHENTICATED);
    }

    public function test_valid_token_reaches_protected_endpoint(): void
    {
        $admin = $this->makeAdmin();
        $token = $this->loginTokens()['access_token'];

        $this->withToken($token)->getJson('/api/admin/auth/me')
            ->assertOk()
            ->assertJsonPath('data.id', $admin->id)
            ->assertJsonPath('data.username', 'tester');
    }

    public function test_refresh_rotates_token_and_invalidates_the_old_one(): void
    {
        $this->makeAdmin();
        $first = $this->loginTokens();

        $second = $this->postJson('/api/admin/auth/refresh', ['refresh_token' => $first['refresh_token']])
            ->assertOk()
            ->json('data');

        $this->assertNotSame($first['refresh_token'], $second['refresh_token']);
        $this->assertNotEmpty($second['access_token']);

        // 旧 refresh 已轮换作废，重放应失败
        $this->postJson('/api/admin/auth/refresh', ['refresh_token' => $first['refresh_token']])
            ->assertStatus(401);
    }

    public function test_refresh_rejects_unknown_token(): void
    {
        $this->makeAdmin();

        $this->postJson('/api/admin/auth/refresh', ['refresh_token' => str_repeat('x', 64)])
            ->assertStatus(401);
    }

    public function test_changing_password_invalidates_existing_access_token(): void
    {
        $admin = $this->makeAdmin();
        $token = $this->loginTokens()['access_token'];

        $this->withToken($token)->getJson('/api/admin/auth/me')->assertOk();

        $admin->forceFill(['password' => 'newpass123'])->save();
        app(AdminAuthService::class)->invalidateAccess($admin);

        $this->withToken($token)->getJson('/api/admin/auth/me')
            ->assertStatus(401)
            ->assertJsonPath('code', AdminAuthService::CODE_STALE);
    }

    public function test_disabling_account_invalidates_existing_access_token(): void
    {
        $admin = $this->makeAdmin();
        $token = $this->loginTokens()['access_token'];

        $admin->forceFill(['status' => 0])->save();

        $this->withToken($token)->getJson('/api/admin/auth/me')
            ->assertStatus(403)
            ->assertJsonPath('code', AdminAuthService::CODE_DISABLED);
    }

    public function test_logout_revokes_refresh_token(): void
    {
        $this->makeAdmin();
        $data = $this->loginTokens();

        $this->postJson('/api/admin/auth/logout', ['refresh_token' => $data['refresh_token']])->assertOk();

        $this->postJson('/api/admin/auth/refresh', ['refresh_token' => $data['refresh_token']])
            ->assertStatus(401);
    }

    public function test_admin_cannot_disable_self(): void
    {
        $admin = $this->makeAdmin();
        $token = $this->loginTokens()['access_token'];

        $this->withToken($token)->patchJson('/api/admin/admins/'.$admin->id, [
            'username' => 'tester',
            'phone' => '13800000000',
            'role' => 'super',
            'status' => 0,
        ])->assertStatus(422)->assertJsonPath('code', 42202);

        $this->assertSame(1, $admin->fresh()->status);
    }

    public function test_admin_role_cannot_manage_admins(): void
    {
        $boss = $this->makeAdmin(['username' => 'boss', 'phone' => '13900000001']);
        $this->makeAdmin(['username' => 'staff', 'phone' => '13900000002', 'role' => Admin::ROLE_ADMIN]);

        $staffToken = $this->loginTokens('staff')['access_token'];

        // 角色门禁优先于业务校验：普通 admin 连列表都读不到，更删不了 super
        $this->withToken($staffToken)->getJson('/api/admin/admins')
            ->assertStatus(403)
            ->assertJsonPath('code', 40302);

        $this->withToken($staffToken)->deleteJson('/api/admin/admins/'.$boss->id)
            ->assertStatus(403);

        $this->assertNull($boss->fresh()->deleted_at);
    }

    public function test_cannot_remove_the_last_super_admin(): void
    {
        $boss = $this->makeAdmin(['username' => 'boss', 'phone' => '13900000001']);
        $token = $this->loginTokens('boss')['access_token'];

        // 只剩一个 super 时，即使是他本人也不能把自己降权
        $this->withToken($token)->patchJson('/api/admin/admins/'.$boss->id, [
            'username' => 'boss',
            'phone' => '13900000001',
            'role' => Admin::ROLE_ADMIN,
            'status' => 1,
        ])->assertStatus(422)->assertJsonPath('code', 42202);

        $this->assertSame(Admin::ROLE_SUPER, $boss->fresh()->role);
    }

    public function test_super_admin_can_create_and_update_another_admin(): void
    {
        $this->makeAdmin();
        $token = $this->loginTokens()['access_token'];

        $created = $this->withToken($token)->postJson('/api/admin/admins', [
            'username' => 'editor',
            'phone' => '13700000009',
            'password' => 'editor123',
            'name' => '内容编辑',
            'role' => 'admin',
        ])->assertOk()->json('data');

        $this->withToken($token)->patchJson('/api/admin/admins/'.$created['id'], [
            'username' => 'editor',
            'phone' => '13700000009',
            'name' => '内容编辑',
            'role' => 'admin',
            'status' => 1,
        ])->assertOk();

        $this->assertDatabaseHas('admins', ['username' => 'editor', 'name' => '内容编辑']);
    }

    public function test_weak_password_is_rejected(): void
    {
        $this->makeAdmin();
        $token = $this->loginTokens()['access_token'];

        $this->withToken($token)->postJson('/api/admin/admins', [
            'username' => 'weak',
            'phone' => '13700000008',
            'password' => '12345678',
            'role' => 'admin',
        ])->assertStatus(422)->assertJsonPath('code', 42200);
    }
}
