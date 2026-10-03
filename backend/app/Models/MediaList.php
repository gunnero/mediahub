<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MediaList extends Model
{
    protected $fillable = ['user_id', 'name', 'description', 'visibility', 'rules', 'cover_style', 'share_token_hash'];

    protected $hidden = ['share_token_hash'];

    protected function casts(): array
    {
        return ['rules' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(MediaListItem::class)->orderBy('position')->orderBy('id');
    }

    public function scopeForUser(Builder $query, User $user): Builder
    {
        return $query->where('user_id', $user->id);
    }
}
