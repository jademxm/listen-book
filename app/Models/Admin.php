<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Tymon\JWTAuth\Contracts\JWTSubject;

/**
 * 后台管理员。
 *
 * 与 users（脚手架遗留）和 wx_users（小程序用户）完全隔离，
 * 通过 config/auth.php 的 admin guard 走 tymon/jwt-auth。
 */
class Admin extends Authenticatable implements JWTSubject
{
    use HasFactory;
    use SoftDeletes;

    public const ROLE_SUPER = 'super';

    public const ROLE_ADMIN = 'admin';

    /** 连续失败多少次后锁定 */
    public const MAX_ATTEMPTS = 5;

    /** 锁定时长（分钟） */
    public const LOCK_MINUTES = 15;

    protected $fillable = [
        'username',
        'phone',
        'password',
        'name',
        'role',
        'status',
        'pwd_version',
        'failed_attempts',
        'locked_until',
        'last_login_at',
        'last_login_ip',
    ];

    /**
     * 与迁移里的默认值对齐。Eloquent 的 create() 不会回读数据库默认值，
     * 缺了这几项会导致新实例的 pwd_version 是 null，签发出来的 token 带着 pwdv=0，
     * 下一次请求就会因为和库里的 1 不一致而被判为失效。
     */
    protected $attributes = [
        'role' => self::ROLE_SUPER,
        'status' => 1,
        'pwd_version' => 1,
        'failed_attempts' => 0,
    ];

    protected $hidden = [
        'password',
        'pwd_version',
        'remember_token',
    ];

    protected $casts = [
        'password' => 'hashed',
        'status' => 'integer',
        'pwd_version' => 'integer',
        'failed_attempts' => 'integer',
        'locked_until' => 'datetime',
        'last_login_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function isSuper(): bool
    {
        return $this->role === self::ROLE_SUPER;
    }

    public function isEnabled(): bool
    {
        return (int) $this->status === 1;
    }

    public function isLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }

    public function displayName(): string
    {
        return $this->name !== null && $this->name !== '' ? $this->name : $this->username;
    }

    /** 供统一响应体使用，避免各处重复挑选字段 */
    public function toProfile(): array
    {
        return [
            'id' => $this->id,
            'username' => $this->username,
            'phone' => $this->phone,
            'name' => $this->name,
            'display_name' => $this->displayName(),
            'role' => $this->role,
            'status' => (int) $this->status,
            'last_login_at' => $this->last_login_at?->format('Y-m-d H:i:s'),
        ];
    }

    public function getJWTIdentifier(): mixed
    {
        return $this->getKey();
    }

    /**
     * pwdv 随密码版本变化，中间件比对后即可让旧 token 立即失效，
     * 不需要额外的黑名单存储也能做到「改密 / 强制下线立刻踢人」。
     */
    public function getJWTCustomClaims(): array
    {
        return [
            'usr' => $this->username,
            'pwdv' => (int) ($this->pwd_version ?: 1),
            'typ' => 'access',
        ];
    }
}
