<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\CatalogService;
use App\Support\Api;
use Illuminate\Http\JsonResponse;

class CatalogController extends Controller
{
    public function bootstrap(): JsonResponse
    {
        return Api::ok([
            'catalog_version' => CatalogService::version(),
            'announcement' => (string) config('services.miniapp.announcement', ''),
            // 内容层名：小程序把这份当覆盖本地默认文案，
            // 换内容形态只改服务端 .env，不必重新发版。
            'content' => [
                'level_names' => [
                    'group' => (string) config('content.level_names.group'),
                    'item' => (string) config('content.level_names.item'),
                ],
                'max_depth' => (int) config('content.max_depth'),
            ],
        ]);
    }

    public function version(): JsonResponse
    {
        return Api::ok(['catalog_version' => CatalogService::version()]);
    }
}
