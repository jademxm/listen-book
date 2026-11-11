<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

/** 页面骨架；数据由页面内 JS 带 Bearer token 调 GET /api/admin/stats。 */
class StatsController extends Controller
{
    public function index(): View
    {
        return view('admin.stats.index');
    }
}
