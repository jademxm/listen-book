<?php

declare(strict_types=1);

use App\Http\Controllers\Api\Admin\AdminController;
use App\Http\Controllers\Api\Admin\AlbumController;
use App\Http\Controllers\Api\Admin\AuthController;
use App\Http\Controllers\Api\Admin\ReorderController;
use App\Http\Controllers\Api\Admin\TrackController;
use App\Http\Middleware\AdminAuth;
use Illuminate\Support\Facades\Route;

/*
 * 由 routes/api.php require 引入，继承 /api 前缀与 api 中间件组（无 session、无 csrf）。
 * 鉴权统一走 Authorization: Bearer <access token>。
 *
 * refresh / logout 不能挂 AdminAuth：access 过期后仍需能刷新和退出。
 */
Route::prefix('admin')->name('api.admin.')->group(function () {
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
    Route::post('auth/refresh', [AuthController::class, 'refresh'])->middleware('throttle:60,1');
    Route::post('auth/logout', [AuthController::class, 'logout']);

    Route::middleware(AdminAuth::class)->group(function () {
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::post('auth/password', [AuthController::class, 'password']);

        Route::get('albums', [AlbumController::class, 'index']);
        Route::post('albums', [AlbumController::class, 'store']);
        Route::post('albums/reorder', [ReorderController::class, 'albums']);
        // PHP 不解析 PATCH 的 multipart body，带封面的更新用 POST + _method=PATCH
        Route::match(['patch', 'post'], 'albums/{album}', [AlbumController::class, 'update'])->whereNumber('album');
        Route::delete('albums/{album}', [AlbumController::class, 'destroy'])->whereNumber('album');

        Route::get('tracks', [TrackController::class, 'index']);
        Route::post('tracks', [TrackController::class, 'store']);
        Route::post('tracks/reorder', [ReorderController::class, 'tracks']);
        Route::match(['patch', 'post'], 'tracks/{track}', [TrackController::class, 'update'])->whereNumber('track');
        Route::delete('tracks/{track}', [TrackController::class, 'destroy'])->whereNumber('track');

        Route::get('admins', [AdminController::class, 'index']);
        Route::post('admins', [AdminController::class, 'store']);
        Route::match(['patch', 'post'], 'admins/{admin}', [AdminController::class, 'update'])->whereNumber('admin');
        Route::delete('admins/{admin}', [AdminController::class, 'destroy'])->whereNumber('admin');
        Route::post('admins/{admin}/password', [AdminController::class, 'resetPassword'])->whereNumber('admin');
    });
});
