<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\CoverUrl;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Album extends Model
{
    protected $fillable = ['title', 'cover_url', 'cover_key', 'description', 'sort_order', 'status'];

    protected $casts = [
        'sort_order' => 'integer',
        'status' => 'integer',
    ];

    public function getCoverUrlAttribute(?string $value): ?string
    {
        return CoverUrl::resolve($this->attributes['cover_key'] ?? null, $value);
    }

    public function tracks(): HasMany
    {
        return $this->hasMany(Track::class);
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', 1);
    }
}
