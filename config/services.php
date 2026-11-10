<?php

return [

    'wechat' => [
        'appid' => env('WX_APPID'),
        'secret' => env('WX_SECRET'),
    ],

    'cos' => [
        'cdn_domain' => env('COS_CDN_DOMAIN', ''),
    ],

    'miniapp' => [
        'announcement' => env('MINIAPP_ANNOUNCEMENT', ''),
    ],

];
