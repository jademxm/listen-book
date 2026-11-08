<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * 封面以 object key 落库，对外 URL 由 COS_CDN_DOMAIN 动态拼接。
 * 换桶 / 换域名只改 .env，不再刷 cover_url。
 */
final class CoverUrl
{
    /** 有 key 走动态拼接；没有 key 时回退历史完整 URL（兼容回填失败的旧数据）。 */
    public static function resolve(?string $key, ?string $legacyUrl = null): ?string
    {
        $domain = rtrim((string) config('services.cos.cdn_domain'), '/');

        if ($key && $domain !== '') {
            return $domain.'/'.ltrim($key, '/');
        }

        return $legacyUrl;
    }

    /** 从历史完整 URL（或相对 key）反推 object key。 */
    public static function keyFrom(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        $key = $value;
        if (str_starts_with($value, 'http://') || str_starts_with($value, 'https://')) {
            $path = parse_url($value, PHP_URL_PATH);
            if (! is_string($path) || $path === '') {
                return null;
            }
            $key = ltrim($path, '/');
        }

        // path-style endpoint 的 URL 里路径首段是 bucket 名，需剥掉
        $bucket = trim((string) config('filesystems.disks.cos.bucket'));
        if ($bucket !== '' && str_starts_with($key, $bucket.'/')) {
            $key = substr($key, strlen($bucket) + 1);
        }

        $key = ltrim($key, '/');

        return $key !== '' ? $key : null;
    }

    /** 把历史 cover_url 回填到 cover_key；返回实际回填条数。 */
    public static function backfill(string $table): int
    {
        $count = 0;

        DB::table($table)
            ->whereNull('cover_key')
            ->whereNotNull('cover_url')
            ->orderBy('id')
            ->get(['id', 'cover_url'])
            ->each(function (object $row) use ($table, &$count) {
                $key = self::keyFrom($row->cover_url);
                if ($key !== null) {
                    DB::table($table)->where('id', $row->id)->update(['cover_key' => $key]);
                    $count++;
                }
            });

        return $count;
    }
}
