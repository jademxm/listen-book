<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Admin;
use App\Models\AdminOperationLog;
use App\Models\AdminRefreshToken;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * 自动记录后台所有写操作，含登录失败与限流拦截。
 *
 * 挂在 /api/admin 整组外层，新增接口只要命名了路由就会被覆盖；
 * GET 与静默刷新（auth.refresh）不记，避免日志自己造日志、refresh 刷屏。
 * 审计是旁路：写日志失败只告警，不影响业务响应。
 */
class LogAdminOperation
{
    /** 绝不落库的敏感字段 */
    private const HIDDEN = [
        'password', 'old_password', 'password_confirmation',
        'refresh_token', 'access_token',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->isMethodSafe()) {
            return $response;
        }

        $action = Str::after((string) $request->route()?->getName(), 'api.admin.');
        if ($action === '' || $action === 'auth.refresh') {
            return $response;
        }

        try {
            $this->record($request, $response, $action);
        } catch (Throwable $e) {
            Log::warning('admin operation log failed', [
                'action' => $action,
                'error' => $e->getMessage(),
            ]);
        }

        return $response;
    }

    private function record(Request $request, Response $response, string $action): void
    {
        $body = $response instanceof JsonResponse ? ($response->getData(true) ?: []) : [];
        $status = $response->getStatusCode();
        $success = $status < 400 && (int) ($body['code'] ?? 0) === 0;

        [$adminId, $username] = $this->actor($request, $body);
        [$targetType, $targetId] = $this->target($request);

        $summary = AdminOperationLog::label($action);
        if ($targetId !== null) {
            $summary .= ' #'.$targetId;
        }
        $title = $request->input('title');
        if (is_string($title) && $title !== '') {
            $summary .= '「'.Str::limit($title, 40).'」';
        }
        if (! $success) {
            $summary .= '（失败：'.Str::limit((string) ($body['message'] ?? $status), 80).'）';
        }

        AdminOperationLog::query()->create([
            'admin_id' => $adminId,
            'username' => $username,
            'action' => $action,
            'summary' => Str::limit($summary, 250),
            'target_type' => $targetType,
            'target_id' => $targetId,
            'payload' => $this->payload($request),
            'method' => $request->method(),
            'path' => '/'.ltrim($request->path(), '/'),
            'status' => $status,
            'success' => $success,
            'ip' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 250),
            'created_at' => now(),
        ]);
    }

    /** @return array{0: int|null, 1: string|null} [admin_id, username 快照] */
    private function actor(Request $request, array $body): array
    {
        $user = $request->user();
        if ($user instanceof Admin) {
            return [$user->id, $user->username];
        }

        // 登录 / 刷新成功：响应里带 profile
        $profile = $body['data']['admin'] ?? null;
        if (is_array($profile) && isset($profile['id'])) {
            return [(int) $profile['id'], (string) ($profile['username'] ?? '')];
        }

        // 登录失败：账号尝试也要留痕
        $account = trim((string) $request->input('account', ''));
        if ($account !== '') {
            $admin = Admin::query()->where('username', $account)->orWhere('phone', $account)->first();

            return [$admin?->id, $admin?->username ?? Str::limit($account, 60)];
        }

        // 退出登录时 access 可能已过期，从 refresh token 反查
        $refresh = (string) $request->input('refresh_token', '');
        if ($refresh !== '') {
            $record = AdminRefreshToken::query()
                ->where('token_hash', hash('sha256', $refresh))
                ->first();

            if ($record !== null) {
                $admin = Admin::withTrashed()->find($record->admin_id);

                return [$record->admin_id, $admin?->username];
            }
        }

        return [null, null];
    }

    /** @return array{0: string|null, 1: int|null} [target_type, target_id] */
    private function target(Request $request): array
    {
        foreach (['album', 'track', 'admin'] as $key) {
            $value = $request->route()?->parameter($key);
            if ($value instanceof Model) {
                return [$key, (int) $value->getKey()];
            }
            if (is_numeric($value)) {
                return [$key, (int) $value];
            }
        }

        return [null, null];
    }

    /** 请求参数快照：脱敏、文件只留名字与大小、超长文本截断 */
    private function payload(Request $request): ?array
    {
        $data = $request->except(self::HIDDEN);

        foreach ($data as $key => $value) {
            if ($value instanceof UploadedFile) {
                $data[$key] = [
                    'file' => Str::limit($value->getClientOriginalName(), 120),
                    'size' => $value->getSize(),
                ];
            } elseif (is_string($value) && mb_strlen($value) > 200) {
                $data[$key] = mb_substr($value, 0, 200).'…';
            }
        }

        return $data === [] ? null : $data;
    }
}
