<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Services\AdminAuthService;
use App\Support\Api;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminController extends Controller
{
    public function __construct(private readonly AdminAuthService $auth) {}

    public function index(Request $request): JsonResponse
    {
        $items = Admin::query()
            ->orderByRaw("CASE WHEN role = 'super' THEN 0 ELSE 1 END")
            ->orderBy('id')
            ->get()
            ->map(fn (Admin $admin) => $admin->toProfile() + [
                'is_self' => $admin->id === $request->user()->id,
            ]);

        return Api::ok(['items' => $items]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'username' => ['required', 'regex:/^[A-Za-z0-9_]{3,32}$/', 'unique:admins,username'],
            'phone' => ['required', 'regex:/^1[3-9]\d{9}$/', 'unique:admins,phone'],
            'password' => ['required', 'string', 'min:8', 'max:64', 'regex:/^(?=.*[A-Za-z])(?=.*\d).+$/'],
            'name' => ['nullable', 'string', 'max:32'],
            'role' => ['nullable', Rule::in([Admin::ROLE_SUPER, Admin::ROLE_ADMIN])],
        ], [
            'username.regex' => '用户名只能是 3-32 位字母、数字或下划线',
            'username.unique' => '该用户名已被占用',
            'phone.regex' => '请输入 11 位手机号',
            'phone.unique' => '该手机号已被占用',
            'password.min' => '密码至少 8 位',
            'password.regex' => '密码需同时包含字母和数字',
        ]);

        $admin = Admin::create([
            'username' => $data['username'],
            'phone' => $data['phone'],
            'password' => $data['password'],
            'name' => $data['name'] ?? null,
            'role' => $data['role'] ?? Admin::ROLE_ADMIN,
            'status' => 1,
        ]);

        return Api::ok($admin->toProfile(), '管理员已创建');
    }

    public function update(Request $request, Admin $admin): JsonResponse
    {
        $data = $request->validate([
            'username' => ['required', 'regex:/^[A-Za-z0-9_]{3,32}$/', Rule::unique('admins', 'username')->ignore($admin->id)],
            'phone' => ['required', 'regex:/^1[3-9]\d{9}$/', Rule::unique('admins', 'phone')->ignore($admin->id)],
            'name' => ['nullable', 'string', 'max:32'],
            'role' => ['required', Rule::in([Admin::ROLE_SUPER, Admin::ROLE_ADMIN])],
            'status' => ['required', 'in:0,1'],
        ], [
            'username.regex' => '用户名只能是 3-32 位字母、数字或下划线',
            'username.unique' => '该用户名已被占用',
            'phone.regex' => '请输入 11 位手机号',
            'phone.unique' => '该手机号已被占用',
        ]);

        /** @var Admin $me */
        $me = $request->user();
        $status = (int) $data['status'];

        if ($admin->id === $me->id && $status === 0) {
            return Api::fail(42202, '不能禁用当前登录的账号', null, 422);
        }

        if ($admin->id === $me->id && $data['role'] !== Admin::ROLE_SUPER && $this->isLastSuper($admin)) {
            return Api::fail(42202, '至少保留一个超级管理员', null, 422);
        }

        if ($admin->isSuper() && ($data['role'] !== Admin::ROLE_SUPER || $status === 0) && $this->isLastSuper($admin)) {
            return Api::fail(42202, '至少保留一个启用的超级管理员', null, 422);
        }

        $admin->fill([
            'username' => $data['username'],
            'phone' => $data['phone'],
            'name' => $data['name'] ?? null,
            'role' => $data['role'],
            'status' => $status,
        ])->save();

        // 禁用或降权立即踢掉已签发的 token
        if ($status === 0) {
            $this->auth->invalidateAccess($admin);
        }

        return Api::ok($admin->toProfile(), '已保存');
    }

    public function destroy(Request $request, Admin $admin): JsonResponse
    {
        /** @var Admin $me */
        $me = $request->user();

        if ($admin->id === $me->id) {
            return Api::fail(42202, '不能删除当前登录的账号', null, 422);
        }

        if ($this->isLastSuper($admin)) {
            return Api::fail(42202, '至少保留一个超级管理员', null, 422);
        }

        $this->auth->invalidateAccess($admin);
        $admin->delete();

        return Api::ok(null, '管理员已移除');
    }

    /** 重置他人密码：不要求旧密码，成功后该账号所有 token 立即失效 */
    public function resetPassword(Request $request, Admin $admin): JsonResponse
    {
        $data = $request->validate([
            'password' => ['required', 'string', 'min:8', 'max:64', 'regex:/^(?=.*[A-Za-z])(?=.*\d).+$/'],
        ], [
            'password.min' => '密码至少 8 位',
            'password.regex' => '密码需同时包含字母和数字',
        ]);

        $admin->forceFill(['password' => $data['password'], 'failed_attempts' => 0, 'locked_until' => null])->save();
        $this->auth->invalidateAccess($admin);

        return Api::ok(null, '密码已重置，该账号需重新登录');
    }

    private function isLastSuper(Admin $admin): bool
    {
        if (! $admin->isSuper()) {
            return false;
        }

        return Admin::query()
            ->where('role', Admin::ROLE_SUPER)
            ->where('status', 1)
            ->whereKeyNot($admin->id)
            ->doesntExist();
    }
}
