<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admins', function (Blueprint $table) {
            $table->id();
            $table->string('username', 32)->unique();
            $table->char('phone', 11)->unique();
            $table->string('password');
            $table->string('name', 32)->nullable();
            // super 可管理其他管理员，admin 只能管内容；本期只做存储与自我操作保护
            $table->string('role', 16)->default('super');
            $table->tinyInteger('status')->default(1);
            // 改密 / 强制下线时自增：token 内的 pwdv 与库中不一致即失效，无需黑名单表
            $table->unsignedInteger('pwd_version')->default(1);
            $table->unsignedTinyInteger('failed_attempts')->default(0);
            $table->timestamp('locked_until')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admins');
    }
};
