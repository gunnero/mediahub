<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PlaybackSession extends Model
{
    protected $fillable = [
        'user_id',
        'playback_source_id',
        'playback_source_item_id',
        'media_link_id',
        'status',
        'started_at',
        'ended_at',
        'last_position_seconds',
        'duration_seconds',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(PlaybackSource::class, 'playback_source_id');
    }

    public function sourceItem(): BelongsTo
    {
        return $this->belongsTo(PlaybackSourceItem::class, 'playback_source_item_id');
    }

    public function mediaLink(): BelongsTo
    {
        return $this->belongsTo(MediaLink::class);
    }

    public function progress(): HasOne
    {
        return $this->hasOne(PlaybackProgress::class);
    }

    public function scopeForUser(Builder $query, User $user): Builder
    {
        return $query->where('user_id', $user->id);
    }

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'last_position_seconds' => 'integer',
            'duration_seconds' => 'integer',
        ];
    }
}
