<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

/**
 * 页面骨架。列表与筛选数据由页面内 JS 带 Bearer token 调 GET /api/admin/tracks。
 */
class TrackController extends Controller
{
    public function index(): View
    {
        return view('admin.tracks.index');
    }
}
