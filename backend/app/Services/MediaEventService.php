<?php

namespace App\Services;

use App\Enums\MediaEventSource;
use App\Enums\MediaEventType;
use App\Models\Episode;
use App\Models\MediaEvent;
use App\Models\Movie;
use App\Models\PlaybackSession;
use App\Models\PlaybackSource;
use App\Models\PlaybackSourceItem;
use App\Models\Show;
use App\Models\User;
use App\Services\Concerns\SanitizesMetadata;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Throwable;

class MediaEventService
{
    use SanitizesMetadata;

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function record(
        User $user,
        MediaEventType|string $eventType,
        ?Model $subject = null,
        array $metadata = [],
        MediaEventSource|string $source = MediaEventSource::System,
        ?CarbonInterface $occurredAt = null,
    ): ?MediaEvent {
        return $this->recordAt($user, $eventType, $subject, $metadata, $source, $occurredAt ?? now());
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function recordHistorical(
        User $user,
        MediaEventType|string $eventType,
        ?Model $subject,
        array $metadata,
        MediaEventSource|string $source,
        CarbonInterface $occurredAt,
        string $dedupeKey,
    ): ?MediaEvent {
        return $this->recordAt($user, $eventType, $subject, [
            ...$metadata,
            'backfill_key' => $dedupeKey,
            'backfilled' => true,
        ], $source, $occurredAt, $dedupeKey);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function recordAt(
        User $user,
        MediaEventType|string $eventType,
        ?Model $subject,
        array $metadata,
        MediaEventSource|string $source,
        CarbonInterface $occurredAt,
        ?string $dedupeKey = null,
    ): ?MediaEvent {
        $eventType = $eventType instanceof MediaEventType ? $eventType->value : $eventType;
        $source = $source instanceof MediaEventSource ? $source->value : $source;

        try {
            if ($dedupeKey) {
                $existing = MediaEvent::forUser($user)
                    ->where('metadata->backfill_key', $dedupeKey)
                    ->first();

                if ($existing) {
                    return $existing;
                }
            }

            return MediaEvent::create([
                'user_id' => $user->id,
                'event_type' => $eventType,
                'subject_type' => $subject ? $subject::class : null,
                'subject_id' => $subject?->getKey(),
                'actor_type' => User::class,
                'actor_id' => $user->id,
                'occurred_at' => $occurredAt,
                'source' => $source,
                'metadata' => $this->sanitizeMetadata($metadata),
            ]);
        } catch (Throwable $exception) {
            Log::warning('Media event recording failed.', [
                'user_id' => $user->id,
                'event_type' => $eventType,
                'source' => $source,
                'error' => $exception::class,
            ]);

            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function timeline(User $user, array $filters = []): array
    {
        return $this->query($user, $filters)
            ->latest('occurred_at')
            ->latest('id')
            ->limit((int) ($filters['limit'] ?? 100))
            ->get()
            ->map(fn (MediaEvent $event): array => $this->payload($event))
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function recent(User $user, int $limit = 50): array
    {
        return $this->timeline($user, ['limit' => $limit]);
    }

    /**
     * @return array{recent:list<array<string, mixed>>,todaySummary:array<string, mixed>,thisWeekSummary:array<string, mixed>}
     */
    public function dashboardTimeline(User $user): array
    {
        $todayStart = now()->startOfDay();
        $weekStart = now()->startOfDay()->subDays(6);
        $recent = $this->meaningfulQuery($user)
            ->latest('occurred_at')
            ->latest('id')
            ->limit(12)
            ->get()
            ->map(fn (MediaEvent $event): array => $this->payload($event))
            ->values()
            ->all();

        return [
            'recent' => $recent,
            'todaySummary' => [
                'total' => $this->meaningfulQuery($user)->where('occurred_at', '>=', $todayStart)->count(),
            ],
            'thisWeekSummary' => [
                'total' => $this->meaningfulQuery($user)->where('occurred_at', '>=', $weekStart)->count(),
            ],
        ];
    }

    private function meaningfulQuery(User $user): Builder
    {
        return MediaEvent::forUser($user)->where(fn ($query) => $query->whereNull('metadata->watch_removed')->orWhere('metadata->watch_removed', false))->whereIn('event_type', [
            MediaEventType::MovieWatched->value,
            MediaEventType::EpisodeWatched->value,
            MediaEventType::RatingCreated->value,
            MediaEventType::RatingUpdated->value,
            MediaEventType::NoteCreated->value,
            MediaEventType::NoteUpdated->value,
            MediaEventType::MovieImported->value,
            MediaEventType::ShowImported->value,
            MediaEventType::EpisodeImported->value,
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function query(User $user, array $filters): Builder
    {
        return MediaEvent::forUser($user)
            ->where(fn ($query) => $query->whereNull('metadata->watch_removed')->orWhere('metadata->watch_removed', false))
            ->when(! filled($filters['event_type'] ?? null), fn (Builder $query) => $query->where('event_type', '!=', MediaEventType::PlaybackProgressed->value))
            ->when(filled($filters['event_type'] ?? null), fn (Builder $query) => $query->where('event_type', $filters['event_type']))
            ->when(filled($filters['source'] ?? null), fn (Builder $query) => $query->where('source', $filters['source']))
            ->when(filled($filters['subject_type'] ?? null), fn (Builder $query) => $query->where('subject_type', $this->subjectType((string) $filters['subject_type'])))
            ->when(filled($filters['subject_id'] ?? null), fn (Builder $query) => $query->where('subject_id', (int) $filters['subject_id']))
            ->when(filled($filters['date_from'] ?? null), fn (Builder $query) => $query->where('occurred_at', '>=', CarbonImmutable::parse((string) $filters['date_from'])->startOfDay()))
            ->when(filled($filters['date_to'] ?? null), fn (Builder $query) => $query->where('occurred_at', '<=', CarbonImmutable::parse((string) $filters['date_to'])->endOfDay()));
    }

    private function subjectType(string $value): string
    {
        return match ($value) {
            'movie' => Movie::class,
            'show' => Show::class,
            'episode' => Episode::class,
            'provider', 'playback_source' => PlaybackSource::class,
            'provider_item', 'playback_source_item' => PlaybackSourceItem::class,
            'playback_session' => PlaybackSession::class,
            default => $value,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(MediaEvent $event): array
    {
        $metadata = $event->metadata ?? [];

        return [
            'id' => $event->id,
            'eventType' => $event->event_type,
            'title' => $this->title($event, $metadata),
            'subtitle' => $this->subtitle($event, $metadata),
            'source' => $event->source,
            'subjectType' => $event->subject_type,
            'subjectId' => $event->subject_id,
            'occurredAt' => $event->occurred_at?->toIso8601String(),
            'group' => $this->group($event->occurred_at),
            'metadata' => $metadata,
        ];
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function title(MediaEvent $event, array $metadata): string
    {
        $title = (string) ($metadata['title'] ?? $metadata['media_title'] ?? $metadata['source_title'] ?? '');
        $rating = $metadata['rating'] ?? null;

        return match ($event->event_type) {
            MediaEventType::MovieWatched->value => 'Watched '.$this->fallbackTitle($title, 'movie'),
            MediaEventType::EpisodeWatched->value => 'Finished '.$this->fallbackTitle($title, 'episode'),
            MediaEventType::MovieUnwatched->value => 'Marked '.$this->fallbackTitle($title, 'movie').' unwatched',
            MediaEventType::EpisodeUnwatched->value => 'Marked '.$this->fallbackTitle($title, 'episode').' unwatched',
            MediaEventType::MovieAdded->value => 'Added '.$this->fallbackTitle($title, 'movie').' to the library',
            MediaEventType::ShowAdded->value => 'Added '.$this->fallbackTitle($title, 'show').' to the library',
            MediaEventType::WatchlistAdded->value => 'Added '.$this->fallbackTitle($title, 'item').' to the watchlist',
            MediaEventType::WatchlistRemoved->value => 'Removed '.$this->fallbackTitle($title, 'item').' from the watchlist',
            MediaEventType::RatingCreated->value,
            MediaEventType::RatingUpdated->value => 'Rated '.$this->fallbackTitle($title, 'item').($rating ? ' '.$rating.'/10' : ''),
            MediaEventType::RatingDeleted->value => 'Cleared rating',
            MediaEventType::NoteCreated->value => 'Added private note',
            MediaEventType::NoteUpdated->value => 'Updated private note',
            MediaEventType::NoteDeleted->value => 'Deleted private note',
            MediaEventType::ProviderCreated->value => 'Attached provider '.$this->fallbackTitle($title, 'source'),
            MediaEventType::ProviderUpdated->value => 'Updated provider '.$this->fallbackTitle($title, 'source'),
            MediaEventType::ProviderTested->value => 'Tested provider '.$this->fallbackTitle($title, 'source'),
            MediaEventType::ProviderRefreshStarted->value => 'Started provider refresh',
            MediaEventType::ProviderRefreshCompleted->value => 'Refreshed provider catalog',
            MediaEventType::ProviderRefreshFailed->value => 'Provider refresh failed',
            MediaEventType::ProviderDisabled->value => 'Disabled provider '.$this->fallbackTitle($title, 'source'),
            MediaEventType::ProviderDeleted->value => 'Deleted provider '.$this->fallbackTitle($title, 'source'),
            MediaEventType::ProviderItemCreated->value => 'Added source item '.$this->fallbackTitle($title, 'item'),
            MediaEventType::ProviderItemLinked->value => 'Linked provider item',
            MediaEventType::ProviderItemUnlinked->value => 'Unlinked provider item',
            MediaEventType::PlaybackStarted->value => 'Started playback',
            MediaEventType::PlaybackCompleted->value => 'Completed playback',
            MediaEventType::MetadataEnriched->value => 'Enriched metadata for '.$this->fallbackTitle($title, 'item'),
            MediaEventType::AIMatchRequested->value => 'Asked Kalveri AI for a match',
            MediaEventType::AIMatchSuggested->value => 'Kalveri AI suggested a match',
            MediaEventType::AIMatchConfirmed->value => 'Confirmed Kalveri AI match',
            MediaEventType::AIMatchRejected->value => 'Rejected Kalveri AI match',
            MediaEventType::MovieImported->value => 'Imported movies',
            MediaEventType::ShowImported->value => 'Imported shows',
            MediaEventType::EpisodeImported->value => 'Imported episodes',
            MediaEventType::BackupCreated->value => 'Created private backup',
            MediaEventType::RestoreCompleted->value => 'Restored private backup',
            default => str($event->event_type)->replace('.', ' ')->title()->toString(),
        };
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function subtitle(MediaEvent $event, array $metadata): string
    {
        return (string) ($metadata['subtitle']
            ?? $metadata['media_type']
            ?? $metadata['kind']
            ?? $metadata['provider_type']
            ?? $event->source);
    }

    private function fallbackTitle(string $title, string $fallback): string
    {
        return trim($title) !== '' ? trim($title) : $fallback;
    }

    private function group(?CarbonInterface $occurredAt): string
    {
        if (! $occurredAt) {
            return 'Earlier';
        }

        if ($occurredAt->isToday()) {
            return 'Today';
        }

        if ($occurredAt->isYesterday()) {
            return 'Yesterday';
        }

        return 'Earlier';
    }
}
