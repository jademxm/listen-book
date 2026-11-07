<?php

namespace App\Providers;

use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // 生产环境经 nginx 反代时，保证 route() / asset() 生成 https 地址
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }
    }
}
