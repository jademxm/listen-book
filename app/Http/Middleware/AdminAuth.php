<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Admin;
use App\Services\AdminAuthService;
use App\Support\Api;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use Tymon\JWTAuth\Exceptions\TokenExpiredException;

/**
 * 后台接口鉴权（Authorization: Bearer <access token>）。
 *
 * 校验三层：token 是否有效 / 账号是否禁用 / pwdv 是否与库中一致。
 * pwdv 一层让改密、禁用、强制下线立即生效，不依赖黑名单缓存。
 */
class AdminAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        try {
            // JWTGuard::payload() 返回 toPlainArray() 后的数组，不是 Payload 对象
            $payload = auth('admin')->payload();
        } catch (TokenExpiredException) {
            return $this->deny(AdminAuthService::CODE_EXPIRED, '登录已过期', 401);
        } catch (Throwable) {
            return $this->deny(AdminAuthService::CODE_UNAUTHENTICATED, '请先登录', 401);
        }

        $admin = Admin::query()->find($payload['sub'] ?? null);

        if ($admin === null) {
            return $this->deny(AdminAuthService::CODE_UNAUTHENTICATED, '请先登录', 401);
        }

        if (! $admin->isEnabled()) {
            return $this->deny(AdminAuthService::CODE_DISABLED, '该账号已被禁用', 403);
        }

        if ((int) ($payload['pwdv'] ?? 0) !== (int) $admin->pwd_version) {
            return $this->deny(AdminAuthService::CODE_STALE, '登录状态已失效，请重新登录', 401);
        }

        // 已解析过一次，直接注入，避免 guard 再次解码
        auth('admin')->setUser($admin);
        $request->setUserResolver(static fn () => $admin);

        return $next($request);
    }

    private function deny(int $code, string $message, int $http): JsonResponse
    {
        return Api::fail($code, $message, null, $http);
    }
}
