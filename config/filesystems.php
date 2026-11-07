<?php

return [

    'default' => env('FILESYSTEM_DISK', 'local'),

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app'),
            'throw' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => env('APP_URL').'/storage',
            'visibility' => 'public',
            'throw' => false,
        ],

        /*
         * 音频磁盘。业务代码始终用 Storage::disk('cos')，驱动只由 .env 的 AUDIO_DRIVER 决定：
         *  - s3（默认）：标准 COS / 轻量对象存储 S3 兼容接口，需 league/flysystem-aws-s3-v3
         *  - local：轻量对象存储一键挂载到服务器目录后直接写本地文件（绕开 SDK 兼容性风险）
         * 切换驱动后必须执行 php artisan config:clear。
         */
        'cos' => env('AUDIO_DRIVER', 's3') === 'local'
            ? [
                'driver' => 'local',
                'root' => env('AUDIO_LOCAL_ROOT', '/mnt/lightcos'),
                'visibility' => 'public',
                'throw' => true,
            ]
            : [
                'driver' => 's3',
                'key' => env('COS_SECRET_ID'),
                'secret' => env('COS_SECRET_KEY'),
                'region' => env('COS_REGION', 'ap-guangzhou'),
                'bucket' => env('COS_BUCKET'),
                'endpoint' => env('COS_ENDPOINT'),
                'url' => env('COS_CDN_DOMAIN'),
                'use_path_style_endpoint' => env('COS_USE_PATH_STYLE', false),
                'throw' => true,
            ],

    ],

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
