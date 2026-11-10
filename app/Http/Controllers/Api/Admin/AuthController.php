<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Services\AdminAuthService;
use App\Support\Api;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Throwable;

class AuthController extends Controller
{
    public function __construct(private readonly AdminAuthService $auth) {}

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'account' => ['required', 'string', 'max:64'],
            'password' => ['required', 'string', 'max:200'],
        ]);

        $result = $this->auth->attempt($data['account'], $data['password'], $request);

        if (! $result['ok']) {
            return Api::fail(
                (int) $result['code'],
                (string) $result['message'],
                null,
                (int) ($result['http'] ?? 401)
            );
        }

        return Api::ok($this->tokenPayload($result), '登录成功');
    }

    public function refresh(Request $request): JsonResponse
    {
        $data = $request->validate([
            'refresh_token' => ['required', 'string', 'max:128'],
        ]);

        $result = $this->auth->refresh($data['refresh_token'], $request);

        if (! $result['ok']) {
            return Api::fail(
                (int) $result['code'],
                (string) $result['message'],
                null,
                (int) ($result['http'] ?? 401)
            );
        }

        return Api::ok($this->tokenPayload($result), '已刷新');
    }

    public function logout(Request $request): JsonResponse
    {
        // 先拉黑当前 access（blacklist 依赖 cache），失败不影响 refresh 的吊销
        try {
            auth('admin')->logout();
        } catch (Throwable) {
            // token 已过期或已失效，无需处理
        }

        $refresh = (string) $request->input('refresh_token', '');
        if ($refresh !== '') {
            $this->auth->revokeRefresh($refresh);
        }

        return Api::ok(null, '已退出登录');
    }

    public function me(Request $request): JsonResponse
    {
        return Api::ok($request->user()->toProfile());
    }

    /** 修改自己的密码：需要旧密码，成功后旧 token 全部失效 */
    public function password(Request $request): JsonResponse
    {
        $data = $request->validate([
            'old_password' => ['required', 'string', 'max:200'],
            'password' => ['required', 'string', 'min:8', 'max:64', 'confirmed'],
        ]);

        /** @var Admin $admin */
        $admin = $request->user();

        if (! Hash::check($data['old_password'], $admin->password)) {
            return Api::fail(42201, '原密码不正确', null, 422);
        }

        $admin->forceFill(['password' => $data['password']])->save();
        $this->auth->invalidateAccess($admin);

        return Api::ok(null, '密码已修改，请重新登录');
    }

    private function tokenPayload(array $result): array
    {
        return [
            'access_token' => $result['access_token'],
            'refresh_token' => $result['refresh_token'],
            'token_type' => $result['token_type'] ?? 'Bearer',
            'expires_in' => $result['expires_in'] ?? 0,
            'admin' => $result['admin']->toProfile(),
        ];
    }
}
