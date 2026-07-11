<?php

namespace App\Services;

use App\Models\Alert;
use App\Models\Episode;
use App\Models\Movie;
use App\Models\NotificationPreference;
use App\Models\Show;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class AlertService
{
    public function __construct(
        private readonly AnalyticsService $analytics,
    ) {}

    public function markRead(User $user, Alert $alert): Alert
    {
        if ($alert->user_id !== $user->id) {
            throw new ModelNotFoundException;
        }

        if ($alert->unread) {
            $alert->forceFill([
                'unread' => false,
                'read_at' => now(),
            ])->save();
        }

        $this->analytics->record('alert.read', $user, [
            'alert_id' => $alert->id,
            'category' => $alert->category,
        ]);

        return $alert->refresh();
    }

    public function markAllRead(User $user): int
    {
        $alerts = Alert::forUser($user)->unread()->get();

        foreach ($alerts as $alert) {
            $alert->forceFill([
                'unread' => false,
                'read_at' => now(),
            ])->save();
        }

        $this->analytics->record('alert.read_all', $user, [
            'updated' => $alerts->count(),
        ]);

        return $alerts->count();
    }

    public function preferences(User $user): NotificationPreference
    {
        return NotificationPreference::firstOrCreate(['user_id' => $user->id], [
            'new_episodes' => true,
            'movie_releases' => true,
            'reminders' => true,
            'in_app_enabled' => true,
            'email_enabled' => false,
        ])->refresh();
    }

    /** @param array<string, bool> $data */
    public function updatePreferences(User $user, array $data): NotificationPreference
    {
        $preferences = $this->preferences($user);
        $preferences->fill($data)->save();

        $this->analytics->record('notification_preferences.updated', $user, [
            'in_app_enabled' => $preferences->in_app_enabled,
            'email_enabled' => $preferences->email_enabled,
        ]);

        return $preferences->refresh();
    }

    public function syncForUser(User $user): int
    {
        $preferences = $this->preferences($user);
        if (! $preferences->in_app_enabled) {
            return 0;
        }

        $created = 0;
        $today = now()->startOfDay();

        if ($preferences->new_episodes) {
            Episode::forUser($user)
                ->with('show')
                ->whereBetween('air_date', [$today->copy()->subDay(), $today->copy()->addDays(14)])
                ->whereHas('show', fn ($query) => $query->forUser($user)->followed())
                ->orderBy('air_date')
                ->limit(50)
                ->get()
                ->each(function (Episode $episode) use ($user, &$created, $today): void {
                    $released = $episode->air_date?->lte($today);
                    $created += $this->upsertGenerated($user, [
                        'dedupe_key' => 'episode-release:'.$episode->id.':'.$episode->air_date?->toDateString(),
                        'category' => $released ? 'new-episodes' : 'upcoming',
                        'title' => $released ? 'New episode available' : 'Episode coming soon',
                        'subtitle' => ($episode->show?->title ?? 'Untitled show').' · '.$this->episodeCode($episode),
                        'due_text' => $this->dueText($episode->air_date),
                        'payload' => ['kind' => 'episode', 'episode_id' => $episode->id, 'show_id' => $episode->show_id],
                    ]);
                });
        }

        if ($preferences->movie_releases) {
            Movie::forUser($user)
                ->toWatch()
                ->whereBetween('release_date', [$today->copy()->subDay(), $today->copy()->addDays(30)])
                ->orderBy('release_date')
                ->limit(50)
                ->get()
                ->each(function (Movie $movie) use ($user, &$created): void {
                    $created += $this->upsertGenerated($user, [
                        'dedupe_key' => 'movie-release:'.$movie->id.':'.$movie->release_date?->toDateString(),
                        'category' => 'movies',
                        'title' => 'Watchlist movie release',
                        'subtitle' => $movie->title,
                        'due_text' => $this->dueText($movie->release_date),
                        'payload' => ['kind' => 'movie', 'movie_id' => $movie->id],
                    ]);
                });
        }

        if ($preferences->reminders) {
            Show::forUser($user)
                ->where('seen_episodes', '>', 0)
                ->whereColumn('aired_episodes', '>', 'seen_episodes')
                ->orderByRaw('(aired_episodes - seen_episodes) desc')
                ->limit(10)
                ->get()
                ->each(function (Show $show) use ($user, &$created): void {
                    $remaining = max(0, $show->aired_episodes - $show->seen_episodes);
                    $created += $this->upsertGenerated($user, [
                        'dedupe_key' => 'unfinished-show:'.$show->id.':'.$show->aired_episodes,
                        'category' => 'reminders',
                        'title' => 'Continue your show',
                        'subtitle' => $show->title.' · '.$remaining.' '.str('episode')->plural($remaining).' ready',
                        'due_text' => 'When you are ready',
                        'payload' => ['kind' => 'show', 'show_id' => $show->id],
                    ]);
                });
        }

        $reviewCount = Episode::forUser($user)
            ->where('metadata_review_status', 'pending')
            ->where('metadata_failure_count', '>', 0)
            ->count();
        if ($reviewCount > 0) {
            $created += $this->upsertGenerated($user, [
                'dedupe_key' => 'metadata-review-required',
                'category' => 'metadata',
                'title' => 'Metadata review needed',
                'subtitle' => $reviewCount.' '.str('episode')->plural($reviewCount).' need manual review',
                'due_text' => 'Action needed',
                'payload' => ['kind' => 'metadata_review', 'count' => $reviewCount],
            ]);
        }

        return $created;
    }

    /** @param array<string, mixed> $data */
    private function upsertGenerated(User $user, array $data): int
    {
        $alert = Alert::firstOrNew(['user_id' => $user->id, 'dedupe_key' => $data['dedupe_key']]);
        $created = ! $alert->exists;
        $alert->fill($data);
        if ($created) {
            $alert->fill(['unread' => true, 'read_at' => null]);
        }
        $alert->save();

        return $created ? 1 : 0;
    }

    private function dueText(?CarbonInterface $date): string
    {
        if (! $date) {
            return 'Date unavailable';
        }
        if ($date->isToday()) {
            return 'Today';
        }
        if ($date->isPast()) {
            return 'Released '.$date->format('M j');
        }

        return 'In '.now()->startOfDay()->diffInDays($date).' days';
    }

    private function episodeCode(Episode $episode): string
    {
        return sprintf('S%02dE%02d', max(0, (int) $episode->season_number), max(0, (int) $episode->episode_number));
    }
}
