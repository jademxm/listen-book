<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Album extends Model
{
    use SoftDeletes;

    protected $fillable = ['title', 'cover_url', 'description', 'sort_order', 'status'];

    protected $casts = [
        'sort_order' => 'integer',
        'status' => 'integer',
    ];

    public function tracks(): HasMany
    {
        return $this->hasMany(Track::class);
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', 1);
    }
}
