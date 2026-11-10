<?php

return [

    /*
    |--------------------------------------------------------------------------
    | 品牌配置（后台站名 / Logo / 主色）
    |--------------------------------------------------------------------------
    |
    | 换项目只改 .env 的三项 BRAND_NAME / BRAND_LOGO / BRAND_COLOR。
    | AppServiceProvider 已把本文件 View::share 成 $brand，Blade 里统一写
    |   {{ $brand['name'] }}  // 站名（后台标题与页头）
    |   {{ $brand['logo'] }}  // Logo 图片地址，留空只显示文字
    |   {{ $brand['color'] }} // 主色，落到 CSS 变量 --accent
    | 不要在视图里再写死站名或色值。
    |
    */

    // 站名：留空时回退 APP_NAME，再回退到「听书」
    'name' => env('BRAND_NAME') ?: env('APP_NAME', '听书'),

    // Logo 地址：站内相对路径（如 /storage/brand/logo.png）或完整 URL；留空则不显示图片
    'logo' => env('BRAND_LOGO', ''),

    // 主色：按钮、链接、表头高亮共用
    'color' => env('BRAND_COLOR', '#185fa5'),

];
