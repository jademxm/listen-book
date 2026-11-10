<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\AlbumController;
use App\Http\Controllers\Admin\AdminUserController;
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\OperationLogController;
use App\Http\Controllers\Admin\TrackController;
use Illuminate\Support\Facades\Route;

/*
 * 由 routes/web.php require 引入，继承 web 中间件组（session / csrf）。
 *
 * 这里只提供页面骨架：不再服务端渲染业务数据，数据由页面内 JS
 * 带 Authorization: Bearer 调 /api/admin/... 获取，登录态判断也在前端完成。
 */
Route::prefix('admin')->name('admin.')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');

    Route::get('/tracks', [TrackController::class, 'index'])->name('tracks.index');
    Route::get('/albums', [AlbumController::class, 'index'])->name('albums.index');
    Route::get('/admins', [AdminUserController::class, 'index'])->name('admins.index');
    Route::get('/logs', [OperationLogController::class, 'index'])->name('logs.index');
});
