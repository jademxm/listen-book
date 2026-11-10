<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

/** 页面骨架；日志数据由页面内 JS 带 Bearer token 调 GET /api/admin/logs。 */
class OperationLogController extends Controller
{
    public function index(): View
    {
        return view('admin.logs.index');
    }
}
