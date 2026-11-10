<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

/**
 * 后台登录页。
 *
 * 真正的认证在 POST /api/admin/auth/login（返回 Bearer token），
 * 这里只负责渲染页面骨架。
 */
class AuthController extends Controller
{
    public function showLogin(): View
    {
        return view('admin.login');
    }
}
