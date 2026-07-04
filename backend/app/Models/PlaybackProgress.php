<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlaybackProgress extends Model
{
    protected $table = 'playback_progress';

    protected $fillable = [
        'user_id',
        'playback_session_id',
        'playback_source_item_id',
        'movie_id',
        'episode_id',
        'position_seconds',
        'duration_seconds',
        'completed',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(PlaybackSession::class, 'playback_session_id');
    }

    public function sourceItem(): BelongsTo
    {
        return $this->belongsTo(PlaybackSourceItem::class, 'playback_source_item_id');
    }

    public function movie(): BelongsTo
    {
        return $this->belongsTo(Movie::class);
    }

    public function episode(): BelongsTo
    {
        return $this->belongsTo(Episode::class);
    }

    public function scopeForUser(Builder $query, User $user): Builder
    {
        return $query->where('user_id', $user->id);
    }

    protected function casts(): array
    {
        return [
            'position_seconds' => 'integer',
            'duration_seconds' => 'integer',
            'completed' => 'boolean',
        ];
    }
}
