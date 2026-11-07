<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
 * RouteServiceProvider 已为本文件套上 web 中间件组（session / cookie / csrf）。
 * 后台页面与接口都在 routes/admin.php 中，前缀 /admin。
 */
Route::get('/', fn () => redirect()->route('admin.tracks.index'));

require __DIR__.'/admin.php';
