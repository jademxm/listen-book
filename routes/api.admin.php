<?php

declare(strict_types=1);

use App\Http\Controllers\Api\Admin\AdminController;
use App\Http\Controllers\Api\Admin\AlbumController;
use App\Http\Controllers\Api\Admin\AuthController;
use App\Http\Controllers\Api\Admin\OperationLogController;
use App\Http\Controllers\Api\Admin\ReorderController;
use App\Http\Controllers\Api\Admin\StatsController;
use App\Http\Controllers\Api\Admin\TrackController;
use App\Http\Middleware\AdminAuth;
use App\Http\Middleware\EnsureSuperAdmin;
use App\Http\Middleware\LogAdminOperation;
use Illuminate\Support\Facades\Route;

/*
 * 由 routes/api.php require 引入，继承 /api 前缀与 api 中间件组（无 session、无 csrf）。
 * 鉴权统一走 Authorization: Bearer <access token>。
 *
 * refresh / logout 不能挂 AdminAuth：access 过期后仍需能刷新和退出。
 * 路由 name 同时是操作日志的 action，新增路由请一并命名。
 */
Route::prefix('admin')->name('api.admin.')->middleware(LogAdminOperation::class)->group(function () {
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1')->name('auth.login');
    Route::post('auth/refresh', [AuthController::class, 'refresh'])->middleware('throttle:60,1')->name('auth.refresh');
    Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');

    Route::middleware(AdminAuth::class)->group(function () {
        Route::get('auth/me', [AuthController::class, 'me'])->name('auth.me');
        Route::post('auth/password', [AuthController::class, 'password'])->name('auth.password');

        // 运营面板：只读，所有登录管理员可见
        Route::get('stats', [StatsController::class, 'index'])->name('stats.index');

        Route::get('albums', [AlbumController::class, 'index'])->name('albums.index');
        Route::post('albums', [AlbumController::class, 'store'])->name('albums.store');
        Route::post('albums/reorder', [ReorderController::class, 'albums'])->name('albums.reorder');
        // PHP 不解析 PATCH 的 multipart body，带封面的更新用 POST + _method=PATCH
        Route::match(['patch', 'post'], 'albums/{album}', [AlbumController::class, 'update'])->whereNumber('album')->name('albums.update');
        Route::delete('albums/{album}', [AlbumController::class, 'destroy'])->whereNumber('album')->name('albums.destroy');

        Route::get('tracks', [TrackController::class, 'index'])->name('tracks.index');
        Route::post('tracks', [TrackController::class, 'store'])->name('tracks.store');
        Route::post('tracks/reorder', [ReorderController::class, 'tracks'])->name('tracks.reorder');
        Route::match(['patch', 'post'], 'tracks/{track}', [TrackController::class, 'update'])->whereNumber('track')->name('tracks.update');
        Route::delete('tracks/{track}', [TrackController::class, 'destroy'])->whereNumber('track')->name('tracks.destroy');

        // 账号管理与操作日志：只有 super 能进，AdminAuth 之外再叠一层角色门禁
        Route::middleware(EnsureSuperAdmin::class)->group(function () {
            Route::get('logs', [OperationLogController::class, 'index'])->name('logs.index');

            Route::get('admins', [AdminController::class, 'index'])->name('admins.index');
            Route::post('admins', [AdminController::class, 'store'])->name('admins.store');
            Route::match(['patch', 'post'], 'admins/{admin}', [AdminController::class, 'update'])->whereNumber('admin')->name('admins.update');
            Route::delete('admins/{admin}', [AdminController::class, 'destroy'])->whereNumber('admin')->name('admins.destroy');
            Route::post('admins/{admin}/password', [AdminController::class, 'resetPassword'])->whereNumber('admin')->name('admins.password');
        });
    });
});
