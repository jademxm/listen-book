<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use RuntimeException;

trait CreatesApplication
{
    /**
     * Creates the application.
     */
    public function createApplication(): Application
    {
        // 本机 .env 的 DB_HOST 指向生产库。配置缓存一旦存在，phpunit.xml 的
        // sqlite/array 覆盖就会失效，RefreshDatabase 的 migrate:fresh 会直接
        // 重建生产库。这里在 bootstrap 之前把这条路彻底堵死。
        if (file_exists(__DIR__.'/../bootstrap/cache/config.php')) {
            throw new RuntimeException('检测到 bootstrap/cache/config.php，测试可能误连生产库。请先执行 php artisan config:clear。');
        }

        $app = require __DIR__.'/../bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        if (config('database.default') !== 'sqlite'
            || config('database.connections.sqlite.database') !== ':memory:') {
            throw new RuntimeException('测试未跑在 sqlite :memory: 上，已终止以免重置生产库。');
        }

        return $app;
    }
}
