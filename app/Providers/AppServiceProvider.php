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

        // 内容层名：后台视图用 $levels['group'] / $levels['item']，
        // 默认「专辑 / 音频」，.env 的 CONTENT_* 可改。
        // 用 composer 而非 share：每次渲染都读最新配置，不会被 boot 时的取值固化。
        View::composer('admin.*', function ($view) {
            $view->with('levels', config('content.level_names'));
        });
    }
}
