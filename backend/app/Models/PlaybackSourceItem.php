<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PlaybackSourceItem extends Model
{
    protected $fillable = [
        'user_id',
        'playback_source_id',
        'external_id',
        'kind',
        'title',
        'status',
        'stream_url',
        'stream_url_hash',
        'metadata',
        'last_seen_at',
    ];

    protected $hidden = [
        'stream_url',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(PlaybackSource::class, 'playback_source_id');
    }

    public function mediaLink(): HasOne
    {
        return $this->hasOne(MediaLink::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(PlaybackSession::class);
    }

    public function progress(): HasOne
    {
        return $this->hasOne(PlaybackProgress::class);
    }

    public function scopeForUser(Builder $query, User $user): Builder
    {
        return $query->where('user_id', $user->id);
    }

    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('status', 'available');
    }

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'stream_url' => 'encrypted',
            'last_seen_at' => 'datetime',
        ];
    }
}
