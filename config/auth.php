<?php

return [

    'defaults' => [
        'guard' => 'web',
        'passwords' => 'users',
    ],

    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'users',
        ],

        // 小程序：Sanctum token，provider 指向 wx_users（与后台 session 完全隔离）
        'wx' => [
            'driver' => 'sanctum',
            'provider' => 'wx_users',
        ],

        // 后台：tymon/jwt-auth，token 走 Authorization: Bearer，provider 指向 admins
        'admin' => [
            'driver' => 'jwt',
            'provider' => 'admins',
        ],
    ],

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => App\Models\User::class,
        ],

        'wx_users' => [
            'driver' => 'eloquent',
            'model' => App\Models\WxUser::class,
        ],

        'admins' => [
            'driver' => 'eloquent',
            'model' => App\Models\Admin::class,
        ],
    ],

    'passwords' => [
        'users' => [
            'provider' => 'users',
            'table' => 'password_reset_tokens',
            'expire' => 60,
            'throttle' => 60,
        ],
    ],

    'password_timeout' => 10800,

];
