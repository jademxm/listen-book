<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 运营面板按 track_id / event_date 聚合，以及「换专辑」按 track_id 回写两张收听表的
 * 冗余 album_id，现有索引只覆盖 (wx_user_id, ...)，不加会走全表扫。
 * 本迁移只加索引，不动数据，可完整回滚。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('play_events', function (Blueprint $table) {
            $table->index('track_id', 'play_events_track_id_index');
            $table->index('event_date', 'play_events_event_date_index');
        });

        Schema::table('play_histories', function (Blueprint $table) {
            $table->index('track_id', 'play_histories_track_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('play_events', function (Blueprint $table) {
            $table->dropIndex('play_events_track_id_index');
            $table->dropIndex('play_events_event_date_index');
        });

        Schema::table('play_histories', function (Blueprint $table) {
            $table->dropIndex('play_histories_track_id_index');
        });
    }
};
