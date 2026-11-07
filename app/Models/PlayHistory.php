<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlayHistory extends Model
{
    protected $fillable = [
        'wx_user_id', 'track_id', 'album_id',
        'progress_sec', 'duration_sec', 'finished', 'last_played_at',
    ];

    protected $casts = [
        'progress_sec' => 'integer',
        'duration_sec' => 'integer',
        'finished' => 'boolean',
        'last_played_at' => 'datetime',
    ];

    public function track(): BelongsTo
    {
        return $this->belongsTo(Track::class);
    }
}
