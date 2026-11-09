<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\AlbumController;
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\ReorderController;
use App\Http\Controllers\Admin\TrackController;
use App\Http\Middleware\AdminAuth;
use Illuminate\Support\Facades\Route;

/*
 * 由 routes/web.php require 引入，继承 web 中间件组（session / csrf）。
 */
Route::prefix('admin')->name('admin.')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->name('login.post')->middleware('throttle:10,1');

    Route::middleware(AdminAuth::class)->group(function () {
        Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

        Route::get('/albums', [AlbumController::class, 'index'])->name('albums.index');
        Route::post('/albums', [AlbumController::class, 'store'])->name('albums.store');
        Route::post('/albums/reorder', [ReorderController::class, 'albums'])->name('albums.reorder');
        Route::patch('/albums/{album}', [AlbumController::class, 'update'])->name('albums.update')->whereNumber('album');
        Route::delete('/albums/{album}', [AlbumController::class, 'destroy'])->name('albums.destroy')->whereNumber('album');

        Route::get('/tracks', [TrackController::class, 'index'])->name('tracks.index');
        Route::post('/tracks', [TrackController::class, 'store'])->name('tracks.store');
        Route::post('/tracks/reorder', [ReorderController::class, 'tracks'])->name('tracks.reorder');
        Route::patch('/tracks/{track}', [TrackController::class, 'update'])->name('tracks.update')->whereNumber('track');
        Route::delete('/tracks/{track}', [TrackController::class, 'destroy'])->name('tracks.destroy')->whereNumber('track');
    });
});
