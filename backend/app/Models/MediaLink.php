<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MediaLink extends Model
{
    protected $fillable = [
        'user_id',
        'playback_source_item_id',
        'movie_id',
        'show_id',
        'episode_id',
        'linked_at',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function sourceItem(): BelongsTo
    {
        return $this->belongsTo(PlaybackSourceItem::class, 'playback_source_item_id');
    }

    public function movie(): BelongsTo
    {
        return $this->belongsTo(Movie::class);
    }

    public function show(): BelongsTo
    {
        return $this->belongsTo(Show::class);
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
            'linked_at' => 'datetime',
        ];
    }
}
