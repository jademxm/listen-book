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
        ]);
    }

    public function version(): JsonResponse
    {
        return Api::ok(['catalog_version' => CatalogService::version()]);
    }
}
