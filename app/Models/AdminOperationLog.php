<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 管理员操作日志（只追加，不修改）。
 *
 * admin_id 没有外键、username 是快照：账号被移除后日志依然可读。
 */
class AdminOperationLog extends Model
{
    public const UPDATED_AT = null;

    /** action（即路由名去掉 api.admin. 前缀）→ 中文动作，供记录与筛选共用 */
    public const ACTIONS = [
        'auth.login' => '登录',
        'auth.logout' => '退出登录',
        'auth.password' => '修改密码',
        'albums.store' => '新建专辑',
        'albums.update' => '编辑专辑',
        'albums.destroy' => '删除专辑',
        'albums.reorder' => '专辑排序',
        'tracks.store' => '上传音频',
        'tracks.update' => '编辑音频',
        'tracks.destroy' => '删除音频',
        'tracks.reorder' => '音频排序',
        'admins.store' => '新建管理员',
        'admins.update' => '编辑管理员',
        'admins.destroy' => '删除管理员',
        'admins.password' => '重置管理员密码',
    ];

    protected $fillable = [
        'admin_id', 'username', 'action', 'summary', 'target_type', 'target_id',
        'payload', 'method', 'path', 'status', 'success', 'ip', 'user_agent', 'created_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'status' => 'integer',
        'success' => 'boolean',
        'created_at' => 'datetime',
    ];

    public static function label(string $action): string
    {
        return self::ACTIONS[$action] ?? $action;
    }
}
