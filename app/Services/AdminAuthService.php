<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Admin;
use App\Models\AdminRefreshToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * 后台登录与双 token 签发。
 *
 * access token 交给 tymon/jwt-auth（HS256，短时效，走 Authorization: Bearer）；
 * refresh token 是随机串，只把 sha256 落库，刷新时轮换（old 立即 revoke）。
 * 两套失效机制互补：access 靠 payload 里的 pwdv 与库中比对，
 * refresh 靠 admin_refresh_tokens 的 revoked_at。
 */
class AdminAuthService
{
    /** 未携带或无法解析 token */
    public const CODE_UNAUTHENTICATED = 40101;

    /** access token 已过期，前端应触发 refresh */
    public const CODE_EXPIRED = 40102;

    /** 密码已变更 / 被强制下线，refresh 也无效 */
    public const CODE_STALE = 40103;

    /** 账号被禁用 */
    public const CODE_DISABLED = 40301;

    /** 连续失败过多被临时锁定 */
    public const CODE_LOCKED = 42901;

    /**
     * 账号不存在时也跑一次等价的 bcrypt 校验，避免通过响应耗时枚举账号。
     * 值本身无意义，只是合法 bcrypt 结构。
     */
    private const DUMMY_HASH = '$2y$12$5HqOKCC9T.UZYOa4WfmC.uuUyze3J1ZZpG433GZKCNPf6nAzq/RhG';

    /**
     * @return array{ok: bool, message?: string, admin?: Admin, access_token?: string, refresh_token?: string, expires_in?: int}
     */
    public function attempt(string $account, string $password, Request $request): array
    {
        $admin = Admin::query()
            ->where('username', $account)
            ->orWhere('phone', $account)
            ->first();

        // 账号不存在与密码错误返回同一句提示，不泄露账号是否存在
        $invalid = [
            'ok' => false,
            'code' => self::CODE_UNAUTHENTICATED,
            'http' => 401,
            'message' => '账号或密码不正确',
        ];

        // 锁定期间直接拒绝且不再累加失败次数，否则攻击者可以一直把账号锁死（DoS）。
        // 这里仍跑一次等价的 bcrypt，保持与正常分支相同的耗时。
        if ($admin !== null && $admin->isLocked()) {
            Hash::check($password, $admin->password);
            $minutes = max(1, (int) ceil(now()->diffInMinutes($admin->locked_until)));

            return [
                'ok' => false,
                'code' => self::CODE_LOCKED,
                'http' => 429,
                'message' => '密码连续错误次数过多，请 '.$minutes.' 分钟后再试',
            ];
        }

        if (! Hash::check($password, $admin?->password ?? self::DUMMY_HASH)) {
            if ($admin !== null) {
                $this->recordFailure($admin);
            }

            return $invalid;
        }

        if (! $admin->isEnabled()) {
            return [
                'ok' => false,
                'code' => self::CODE_DISABLED,
                'http' => 403,
                'message' => '该账号已被禁用',
            ];
        }

        $admin->forceFill([
            'failed_attempts' => 0,
            'locked_until' => null,
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ])->save();

        return ['ok' => true, 'admin' => $admin] + $this->issue($admin, $request);
    }

    /** 签发一对新 token，并把 refresh 落库 */
    public function issue(Admin $admin, Request $request): array
    {
        $access = auth('admin')->login($admin);

        $raw = Str::random(64);
        AdminRefreshToken::create([
            'admin_id' => $admin->id,
            'token_hash' => hash('sha256', $raw),
            'expires_at' => now()->addMinutes((int) config('jwt.refresh_ttl', 10080)),
            'user_agent' => Str::limit((string) $request->userAgent(), 255, ''),
            'ip' => $request->ip(),
        ]);

        return [
            'access_token' => $access,
            'refresh_token' => $raw,
            'token_type' => 'Bearer',
            'expires_in' => (int) config('jwt.ttl', 120) * 60,
        ];
    }

    /**
     * 轮换刷新：旧 refresh 立即作废，签发新的 access + refresh。
     *
     * @return array{ok: bool, message?: string, admin?: Admin, access_token?: string, refresh_token?: string, expires_in?: int}
     */
    public function refresh(string $raw, Request $request): array
    {
        $record = AdminRefreshToken::query()
            ->where('token_hash', hash('sha256', $raw))
            ->first();

        if ($record === null || ! $record->isUsable()) {
            return [
                'ok' => false,
                'code' => self::CODE_UNAUTHENTICATED,
                'http' => 401,
                'message' => '登录已失效，请重新登录',
            ];
        }

        $admin = Admin::query()->find($record->admin_id);

        if ($admin === null || ! $admin->isEnabled()) {
            $record->forceFill(['revoked_at' => now()])->save();

            return [
                'ok' => false,
                'code' => self::CODE_DISABLED,
                'http' => 403,
                'message' => '账号不可用，请重新登录',
            ];
        }

        $record->forceFill(['revoked_at' => now()])->save();

        // 顺手清掉该管理员已过期或已作废的历史记录，避免表无限增长
        AdminRefreshToken::query()
            ->where('admin_id', $admin->id)
            ->where(fn ($q) => $q->whereNotNull('revoked_at')->orWhere('expires_at', '<', now()))
            ->delete();

        return ['ok' => true, 'admin' => $admin] + $this->issue($admin, $request);
    }

    public function revokeRefresh(string $raw): void
    {
        AdminRefreshToken::query()
            ->where('token_hash', hash('sha256', $raw))
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }

    /** 改密 / 禁用 / 强制下线：版本号 +1，已签发的 access 全部立即失效 */
    public function invalidateAccess(Admin $admin): void
    {
        // 必须落库后再自增，否则实例上的 null 会让 +1 得到 1，与库里已有的 1 相同、等于没变
        $current = (int) ($admin->pwd_version ?: Admin::query()->whereKey($admin->getKey())->value('pwd_version') ?: 1);

        $admin->forceFill(['pwd_version' => $current + 1])->save();
        AdminRefreshToken::query()
            ->where('admin_id', $admin->id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }

    private function recordFailure(Admin $admin): void
    {
        $attempts = min(Admin::MAX_ATTEMPTS, (int) $admin->failed_attempts + 1);

        // 上一轮锁定已过期则重新计数
        if ($admin->locked_until !== null && $admin->locked_until->isPast()) {
            $attempts = 1;
        }

        $admin->forceFill([
            'failed_attempts' => $attempts,
            'locked_until' => $attempts >= Admin::MAX_ATTEMPTS
                ? now()->addMinutes(Admin::LOCK_MINUTES)
                : null,
        ])->save();
    }
}
