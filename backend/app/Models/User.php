<?php

namespace App\Models;

use App\Enums\ProfileVisibility;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'username',
        'display_name',
        'full_name',
        'bio',
        'avatar_path',
        'avatar_variants',
        'public_profile_enabled',
        'show_avatar',
        'profile_visibility',
        'profile_slug',
        'country',
        'favorite_genres',
        'favorite_movie_ids',
        'favorite_show_ids',
        'featured_list_ids',
        'show_statistics',
        'show_favorite_movies',
        'show_favorite_shows',
        'show_public_lists',
        'show_recent_activity',
        'allow_friend_requests',
        'allow_profile_sharing',
        'allow_search_discovery',
        'email',
        'password',
        'role',
        'status',
        'last_login_at',
        'joined_at',
        'last_active_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->status === UserStatus::Active
            && in_array($this->role, [UserRole::Owner, UserRole::Admin], true);
    }

    public function invitesCreated(): HasMany
    {
        return $this->hasMany(Invite::class, 'invited_by_user_id');
    }

    public function friendshipsRequested(): HasMany
    {
        return $this->hasMany(Friendship::class, 'requester_user_id');
    }

    public function friendshipsReceived(): HasMany
    {
        return $this->hasMany(Friendship::class, 'addressee_user_id');
    }

    public function friendInvitesCreated(): HasMany
    {
        return $this->hasMany(FriendInvite::class, 'inviter_user_id');
    }

    public function alerts(): HasMany
    {
        return $this->hasMany(Alert::class);
    }

    public function analyticsEvents(): HasMany
    {
        return $this->hasMany(AnalyticsEvent::class, 'actor_user_id');
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class, 'actor_user_id');
    }

    public function shows(): HasMany
    {
        return $this->hasMany(Show::class);
    }

    public function episodes(): HasMany
    {
        return $this->hasMany(Episode::class);
    }

    public function episodeWatches(): HasMany
    {
        return $this->hasMany(EpisodeWatch::class);
    }

    public function movies(): HasMany
    {
        return $this->hasMany(Movie::class);
    }

    public function movieWatches(): HasMany
    {
        return $this->hasMany(MovieWatch::class);
    }

    public function playbackSources(): HasMany
    {
        return $this->hasMany(PlaybackSource::class);
    }

    public function playbackSourceItems(): HasMany
    {
        return $this->hasMany(PlaybackSourceItem::class);
    }

    public function mediaLinks(): HasMany
    {
        return $this->hasMany(MediaLink::class);
    }

    public function playbackSessions(): HasMany
    {
        return $this->hasMany(PlaybackSession::class);
    }

    public function playbackProgress(): HasMany
    {
        return $this->hasMany(PlaybackProgress::class);
    }

    public function ratings(): HasMany
    {
        return $this->hasMany(Rating::class);
    }

    public function notes(): HasMany
    {
        return $this->hasMany(Note::class);
    }

    public function mediaLists(): HasMany
    {
        return $this->hasMany(MediaList::class);
    }

    public function mediaListItems(): HasMany
    {
        return $this->hasMany(MediaListItem::class);
    }

    public function notificationPreference(): HasOne
    {
        return $this->hasOne(NotificationPreference::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', UserStatus::Active);
    }

    public function scopeAdmins(Builder $query): Builder
    {
        return $query->whereIn('role', [UserRole::Owner, UserRole::Admin]);
    }

    public function scopeMembers(Builder $query): Builder
    {
        return $query->where('role', UserRole::Member);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'joined_at' => 'datetime',
            'last_active_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'status' => UserStatus::class,
            'profile_visibility' => ProfileVisibility::class,
            'favorite_genres' => 'array',
            'avatar_variants' => 'array',
            'favorite_movie_ids' => 'array',
            'favorite_show_ids' => 'array',
            'featured_list_ids' => 'array',
            'public_profile_enabled' => 'boolean',
            'show_avatar' => 'boolean',
            'show_statistics' => 'boolean',
            'show_favorite_movies' => 'boolean',
            'show_favorite_shows' => 'boolean',
            'show_public_lists' => 'boolean',
            'show_recent_activity' => 'boolean',
            'allow_friend_requests' => 'boolean',
            'allow_profile_sharing' => 'boolean',
            'allow_search_discovery' => 'boolean',
        ];
    }
}
