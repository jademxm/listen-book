<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 内容表改为「彻底删」：移除软删列，不再留既看不到也恢复不了的僵尸记录。
 * admins 表仍保留软删——日志里的 admin_id 需要能追溯已移除的账号。
 *
 * 历史软删行的桶对象在 v0.10 时已被立即删除，属于不可恢复数据；
 * 若只删列、不清行，模型失去 SoftDeletes 作用域后它们会重新出现在列表里，
 * 且播放历史会指向没有音频的条目。因此先清数据再删列。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('tracks', 'deleted_at')) {
            $this->purgeLegacySoftDeletes();
        }

        foreach (['albums', 'tracks'] as $tableName) {
            if (! Schema::hasColumn($tableName, 'deleted_at')) {
                continue;
            }

            // Laravel 10 的 SQLite grammar 删列要 doctrine/dbal；SQLite 3.35+ 原生支持，直接下发 DDL
            if (DB::connection()->getDriverName() === 'sqlite') {
                DB::statement("ALTER TABLE {$tableName} DROP COLUMN deleted_at");
            } else {
                Schema::table($tableName, fn (Blueprint $table) => $table->dropColumn('deleted_at'));
            }
        }
    }

    private function purgeLegacySoftDeletes(): void
    {
        $trackIds = DB::table('tracks')->whereNotNull('deleted_at')->pluck('id')->all();
        $albumIds = DB::table('albums')->whereNotNull('deleted_at')->pluck('id')->all();

        if ($trackIds !== []) {
            DB::table('play_histories')->whereIn('track_id', $trackIds)->delete();
            DB::table('play_events')->whereIn('track_id', $trackIds)->delete();
        }

        if ($albumIds !== []) {
            DB::table('play_histories')->whereIn('album_id', $albumIds)->delete();
            DB::table('play_events')->whereIn('album_id', $albumIds)->delete();
            // 挂在已软删专辑下的所有曲目（含未软删的历史遗留行）一并清掉
            DB::table('tracks')->whereIn('album_id', $albumIds)->delete();
            DB::table('albums')->whereIn('id', $albumIds)->delete();
        }

        DB::table('tracks')->whereIn('id', $trackIds)->delete();
    }

    public function down(): void
    {
        foreach (['albums', 'tracks'] as $tableName) {
            if (! Schema::hasColumn($tableName, 'deleted_at')) {
                Schema::table($tableName, fn (Blueprint $table) => $table->softDeletes());
            }
        }
    }
};
