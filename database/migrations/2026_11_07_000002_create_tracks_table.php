<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tracks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('album_id');
            $table->string('title', 150);
            $table->string('cover_url', 500)->nullable();
            $table->string('object_key', 500);
            $table->string('mime', 60)->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->unsignedInteger('duration_sec')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->unsignedTinyInteger('status')->default(1)->comment('0 draft, 1 published');
            $table->unsignedBigInteger('play_count')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['album_id', 'status', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tracks');
    }
};
