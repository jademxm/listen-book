<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Admin;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

/**
 * 创建后台管理员。
 *
 * 迁移后 admins 表是空的，而登录必须走数据库账号，
 * 所以首次部署需要用这条命令引导出第一个管理员：
 *
 *   php artisan admin:create
 *   php artisan admin:create --username=admin --phone=13800000000 --password=abc12345
 */
class CreateAdmin extends Command
{
    protected $signature = 'admin:create
        {--username= : 登录用户名（3-32 位字母、数字、下划线）}
        {--phone= : 11 位手机号}
        {--password= : 初始密码（至少 8 位，需含字母和数字）}
        {--name= : 显示名，可留空}
        {--role=super : 角色，super 或 admin}';

    protected $description = '创建后台管理员账号';

    public function handle(): int
    {
        $username = (string) ($this->option('username') ?: $this->ask('用户名'));
        $phone = (string) ($this->option('phone') ?: $this->ask('手机号'));
        $name = (string) ($this->option('name') ?: $this->ask('显示名（可留空）', ''));
        $role = (string) $this->option('role');

        $password = (string) $this->option('password');
        if ($password === '') {
            $password = (string) $this->secret('初始密码（至少 8 位，需含字母和数字）');
        }

        $validator = Validator::make([
            'username' => $username,
            'phone' => $phone,
            'password' => $password,
            'role' => $role,
        ], [
            'username' => ['required', 'regex:/^[A-Za-z0-9_]{3,32}$/', 'unique:admins,username'],
            'phone' => ['required', 'regex:/^1[3-9]\d{9}$/', 'unique:admins,phone'],
            'password' => ['required', 'string', 'min:8', 'max:64', 'regex:/^(?=.*[A-Za-z])(?=.*\d).+$/'],
            'role' => ['required', 'in:super,admin'],
        ], [
            'username.regex' => '用户名只能是 3-32 位字母、数字或下划线',
            'username.unique' => '该用户名已被占用',
            'phone.regex' => '请输入 11 位手机号',
            'phone.unique' => '该手机号已被占用',
            'password.min' => '密码至少 8 位',
            'password.regex' => '密码需同时包含字母和数字',
            'role.in' => '角色只能是 super 或 admin',
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $admin = Admin::create([
            'username' => $username,
            'phone' => $phone,
            'password' => $password,
            'name' => $name !== '' ? $name : null,
            'role' => $role,
            'status' => 1,
        ]);

        $this->info("管理员已创建：#{$admin->id} {$admin->username}（{$admin->role}）");

        return self::SUCCESS;
    }
}
