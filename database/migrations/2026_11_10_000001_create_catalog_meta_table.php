<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * 目录版本号落库。
 *
 * 之前存在 Cache::forever('catalog:version')，值从 1 起单调 +1。问题是缓存是可清的：
 * `php artisan cache:clear` / `optimize:clear` 之后 get(key, 1) 直接回到 1，而小程序
 * 本地缓存里记着的还是 3 —— 服务端版本涨回 3 的那一刻，客户端比对「3 === 3」命中
 * 旧列表，从此再也不刷新（后台拖了顺序、小程序纹丝不动）。
 *
 * 改为数据库单行 + 时间戳起点：只增不减，且初始值远超历史客户端缓存里的任何小整数，
 * 上线后所有设备必然会强制刷新一次。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_meta', function (Blueprint $table) {
            $table->string('key', 64)->primary();
            $table->unsignedBigInteger('value')->default(0);
            $table->timestamps();
        });

        $now = now();

        DB::table('catalog_meta')->insert([
            'key' => 'catalog_version',
            'value' => time(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_meta');
    }
};
