<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 「我的」模块 v1.0：收听事件明细。既有业务表结构不变，本表可独立回滚。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('play_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('wx_user_id');
            $table->unsignedBigInteger('track_id');
            $table->unsignedBigInteger('album_id')->nullable();
            $table->string('session_id', 64);
            $table->string('client_event_id', 64)->unique(); // 幂等
            $table->string('type', 16);                       // start/tick/pause/end/ended/catchup
            $table->unsignedInteger('position_sec')->default(0);
            $table->unsignedInteger('effective_sec')->default(0);
            $table->date('event_date');                        // 北京时区自然日
            $table->timestamp('occurred_at')->nullable();      // 客户端时间
            $table->timestamps();
            $table->index(['wx_user_id', 'event_date']);
            $table->index(['wx_user_id', 'session_id', 'track_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('play_events');
    }
};
