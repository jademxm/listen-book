<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Track extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'album_id', 'title', 'cover_url', 'object_key', 'mime',
        'size_bytes', 'duration_sec', 'sort_order', 'status', 'play_count',
    ];

    protected $casts = [
        'album_id' => 'integer',
        'size_bytes' => 'integer',
        'duration_sec' => 'integer',
        'sort_order' => 'integer',
        'status' => 'integer',
        'play_count' => 'integer',
    ];

    public function album(): BelongsTo
    {
        return $this->belongsTo(Album::class);
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', 1);
    }

    public function getUrlAttribute(): string
    {
        return rtrim((string) config('services.cos.cdn_domain'), '/').'/'.ltrim((string) $this->object_key, '/');
    }
}
