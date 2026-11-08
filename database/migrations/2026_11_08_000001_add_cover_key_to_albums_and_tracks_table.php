<?php

declare(strict_types=1);

use App\Support\CoverUrl;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['albums', 'tracks'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->string('cover_key', 500)->nullable()->after('cover_url');
            });

            // 历史数据存的是完整 URL，反推出 object key，此后封面 URL 随 COS_CDN_DOMAIN 走
            CoverUrl::backfill($tableName);
        }
    }

    public function down(): void
    {
        foreach (['albums', 'tracks'] as $tableName) {
            if (Schema::hasColumn($tableName, 'cover_key')) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->dropColumn('cover_key');
                });
            }
        }
    }
};
