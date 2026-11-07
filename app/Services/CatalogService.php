<?php

declare(strict_types=1);

namespace App\Services;

use Closure;
use Illuminate\Support\Facades\Cache;

class CatalogService
{
    private const VERSION_KEY = 'catalog:version';

    public static function version(): int
    {
        return (int) Cache::get(self::VERSION_KEY, 1);
    }

    public static function bump(): int
    {
        $next = self::version() + 1;
        Cache::forever(self::VERSION_KEY, $next);

        return $next;
    }

    /** 版本号参与缓存键，bump 后自动读新缓存，无需逐项清理 */
    public static function remember(string $key, Closure $callback, int $ttl = 3600): mixed
    {
        return Cache::remember('catalog:v'.self::version().':'.$key, $ttl, $callback);
    }
}
