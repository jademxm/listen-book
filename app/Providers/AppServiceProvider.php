<?php

namespace App\Providers;

use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
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

        // 品牌变量：后台所有视图共用 $brand['name'|'logo'|'color']，
        // 换项目只改 .env 的 BRAND_*，视图里不再出现站名与色值字面量。
        View::share('brand', config('brand'));
    }
}
