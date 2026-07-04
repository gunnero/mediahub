<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'status',
        'last_login_at',
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
            'password' => 'hashed',
            'role' => UserRole::class,
            'status' => UserStatus::class,
        ];
    }
}
