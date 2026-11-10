<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Admin;
use App\Support\Api;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 角色门禁：管理员账号管理与操作日志只对 super 开放。
 *
 * 挂在 AdminAuth 之后，因此 $request->user() 一定是已经过
 * enabled / pwdv 校验的 Admin 实例。
 */
class EnsureSuperAdmin
{
    public const CODE_FORBIDDEN = 40302;

    public function handle(Request $request, Closure $next): Response
    {
        $admin = $request->user();

        if (! $admin instanceof Admin || ! $admin->isSuper()) {
            return Api::fail(self::CODE_FORBIDDEN, '仅超级管理员可执行此操作', null, 403);
        }

        return $next($request);
    }
}
