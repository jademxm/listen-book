<?php

declare(strict_types=1);

namespace App\Services;

use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class CatalogService
{
    private const META_TABLE = 'catalog_meta';

    private const VERSION_KEY = 'catalog_version';

    /** 迁移未执行时只记一次日志，避免每个请求刷一条 warning */
    private static bool $warned = false;

    /**
     * 目录版本号。
     *
     * 必须落库、必须单调递增：它同时是「服务端列表缓存的命名空间」和「客户端本地缓存的
     * 有效性判据」。之前存在 Cache 里，任何一次 cache:clear / optimize:clear 都会让它
     * 回退到 1，客户端手里那个同号的旧缓存就会被误判为最新，从此永不刷新。
     */
    public static function version(): int
    {
        try {
            $value = DB::table(self::META_TABLE)->where('key', self::VERSION_KEY)->value('value');

            if ($value !== null) {
                return (int) $value;
            }
        } catch (Throwable $e) {
            // 迁移未执行时不要在接口层抛 500：退化成时间派生值，只会更大、不会更小
            self::warnOnce('catalog: 版本表不可用，回退到时间派生值', $e);

            return self::timestampVersion();
        }

        return self::bump();
    }

    public static function bump(): int
    {
        $now = now();

        try {
            $affected = DB::table(self::META_TABLE)
                ->where('key', self::VERSION_KEY)
                ->update(['value' => DB::raw('value + 1'), 'updated_at' => $now]);

            if ($affected === 0) {
                // 行不存在（首次部署 / 被手工清表），用当前时间戳建行，避免从 1 起算
                DB::table(self::META_TABLE)->insertOrIgnore([
                    'key' => self::VERSION_KEY,
                    'value' => self::timestampVersion(),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            return self::version();
        } catch (Throwable $e) {
            self::warnOnce('catalog: 版本自增失败', $e);

            return self::timestampVersion();
        }
    }

    /** 版本号参与缓存键，bump 后自动读新缓存，无需逐项清理 */
    public static function remember(string $key, Closure $callback, int $ttl = 3600): mixed
    {
        return Cache::remember('catalog:v'.self::version().':'.$key, $ttl, $callback);
    }

    /** 时间戳派生值：永远大于历史上用过的 1..N 小整数，不会和旧客户端缓存撞号 */
    private static function timestampVersion(): int
    {
        return (int) now()->format('YmdHis');
    }

    private static function warnOnce(string $message, Throwable $e): void
    {
        if (self::$warned) {
            return;
        }

        self::$warned = true;
        Log::warning($message.'（是否漏跑 php artisan migrate？）', ['error' => $e->getMessage()]);
    }
}
