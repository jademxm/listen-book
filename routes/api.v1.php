<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AlbumController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CatalogController;
use App\Http\Controllers\Api\V1\HistoryController;
use Illuminate\Support\Facades\Route;

/*
 * 由 routes/api.php require 引入，继承 /api 前缀与 api 中间件组。
 */
Route::prefix('v1')->group(function () {
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:30,1');

    Route::get('/bootstrap', [CatalogController::class, 'bootstrap']);
    Route::get('/catalog/version', [CatalogController::class, 'version']);
    Route::get('/albums', [AlbumController::class, 'index']);
    Route::get('/albums/{album}/tracks', [AlbumController::class, 'tracks'])->whereNumber('album');

    // guard「wx」在 config/auth.php 中定义，provider 指向 wx_users，与后台 session 认证隔离
    Route::middleware('auth:wx')->group(function () {
        Route::get('/me/histories', [HistoryController::class, 'index']);
        Route::post('/me/histories', [HistoryController::class, 'store'])->middleware('throttle:120,1');
        Route::delete('/me/histories', [HistoryController::class, 'clear']);
        Route::delete('/me/histories/{trackId}', [HistoryController::class, 'destroy'])->whereNumber('trackId');
    });
});
