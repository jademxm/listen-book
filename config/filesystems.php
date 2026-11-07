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

        // 腾讯云 COS：使用 S3 兼容协议，需 league/flysystem-aws-s3-v3
        'cos' => [
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
