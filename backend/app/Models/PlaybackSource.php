<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PlaybackSource extends Model
{
    protected $fillable = [
        'user_id',
        'name',
        'provider_type',
        'status',
        'metadata',
        'settings',
        'last_synced_at',
    ];

    protected $hidden = [
        'settings',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PlaybackSourceItem::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(PlaybackSession::class);
    }

    public function scopeForUser(Builder $query, User $user): Builder
    {
        return $query->where('user_id', $user->id);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'settings' => 'encrypted:array',
            'last_synced_at' => 'datetime',
        ];
    }
}
