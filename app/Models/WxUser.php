<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class WxUser extends Authenticatable
{
    use HasApiTokens;

    protected $fillable = ['openid', 'unionid', 'last_active_at'];

    protected $hidden = ['openid', 'unionid'];

    protected $casts = ['last_active_at' => 'datetime'];

    public function histories(): HasMany
    {
        return $this->hasMany(PlayHistory::class);
    }
}
