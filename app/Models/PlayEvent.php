<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlayEvent extends Model
{
    protected $fillable = [
        'wx_user_id', 'track_id', 'album_id',
        'session_id', 'client_event_id', 'type',
        'position_sec', 'effective_sec', 'event_date', 'occurred_at',
    ];

    protected $casts = [
        'position_sec' => 'integer',
        'effective_sec' => 'integer',
        'occurred_at' => 'datetime',
    ];

    public function track(): BelongsTo
    {
        return $this->belongsTo(Track::class);
    }
}
