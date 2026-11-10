<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
 * RouteServiceProvider 已为本文件套上 /api 前缀与 api 中间件组。
 * 小程序业务 API 约定：/api/v1/...；后台管理 API 约定：/api/admin/...（Bearer JWT）。
 */
require __DIR__.'/api.v1.php';
require __DIR__.'/api.admin.php';
