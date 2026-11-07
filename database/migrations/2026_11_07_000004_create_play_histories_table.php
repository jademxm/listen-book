<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('play_histories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('wx_user_id');
            $table->unsignedBigInteger('track_id');
            $table->unsignedBigInteger('album_id')->nullable();
            $table->unsignedInteger('progress_sec')->default(0);
            $table->unsignedInteger('duration_sec')->nullable();
            $table->unsignedTinyInteger('finished')->default(0);
            $table->timestamp('last_played_at')->nullable();
            $table->timestamps();
            $table->unique(['wx_user_id', 'track_id']);
            $table->index(['wx_user_id', 'last_played_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('play_histories');
    }
};
