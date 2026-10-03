<?php

namespace App\Services;

use App\Enums\MediaEventSource;
use App\Enums\MediaEventType;
use App\Models\Episode;
use App\Models\EpisodeWatch;
use App\Models\MediaEvent;
use App\Models\Movie;
use App\Models\MovieWatch;
use App\Models\Note;
use App\Models\Rating;
use App\Models\Show;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

class MediaDiaryBackfillService
{
    public function __construct(private readonly MediaEventService $events) {}

    /** @return array{planned:int,created:int,existing:int,skipped:int,movie_watches:int,episode_watches:int,ratings:int,notes:int,imports:int} */
    public function backfill(User $user, bool $dryRun = false): array
    {
        $summary = $this->summary();

        MovieWatch::forUser($user)->with('movie')->watched()->orderBy('id')->each(function (MovieWatch $watch) use ($dryRun, $user, &$summary): void {
            if (! $watch->movie || $watch->movie->user_id !== $user->id || ! $watch->watched_at) {
                $summary['skipped']++;

                return;
            }

            foreach (range(1, max(1, $watch->watch_count)) as $watchNumber) {
                $key = 'history:movie-watch:'.$watch->id.':'.$watchNumber;
                $summary['planned']++;
                $summary['movie_watches']++;
                if ($watchNumber === 1 && $this->existingWatchEvent($user, MediaEventType::MovieWatched, $watch->movie, $watch->watched_at)) {
                    $summary['existing']++;

                    continue;
                }

                $this->create($summary, $dryRun, $user, MediaEventType::MovieWatched, $watch->movie, [
                    'title' => $watch->movie->title,
                    'media_type' => 'movie',
                    'watched_at' => $watch->watched_at->toIso8601String(),
                    'watch_id' => $watch->id,
                    'runtime' => $watch->runtime,
                    'watch_number' => $watchNumber,
                ], $this->source($watch->source), $watch->watched_at, $key);
            }
        });

        EpisodeWatch::forUser($user)->with(['episode', 'show'])->watched()->orderBy('id')->each(function (EpisodeWatch $watch) use ($dryRun, $user, &$summary): void {
            if (! $watch->episode || ! $watch->show || $watch->episode->user_id !== $user->id || $watch->show->user_id !== $user->id || ! $watch->watched_at) {
                $summary['skipped']++;

                return;
            }

            $key = 'history:episode-watch:'.$watch->id;
            $summary['planned']++;
            $summary['episode_watches']++;
            if ($this->existingWatchEvent($user, MediaEventType::EpisodeWatched, $watch->episode, $watch->watched_at)) {
                $summary['existing']++;

                return;
            }

            $this->create($summary, $dryRun, $user, MediaEventType::EpisodeWatched, $watch->episode, [
                'title' => $watch->episode->title ?: $watch->show->title,
                'subtitle' => $watch->show->title,
                'media_type' => 'episode',
                'show_id' => $watch->show_id,
                'watched_at' => $watch->watched_at->toIso8601String(),
                'watch_id' => $watch->id,
                'runtime' => $watch->runtime,
            ], $this->source($watch->source), $watch->watched_at, $key);
        });

        Rating::forUser($user)->orderBy('id')->each(function (Rating $rating) use ($dryRun, $user, &$summary): void {
            $media = $this->media($user, $rating->media_type, $rating->media_id);
            if (! $media) {
                $summary['skipped']++;

                return;
            }

            $summary['planned']++;
            $summary['ratings']++;
            $key = 'history:rating:'.$rating->id;
            if ($this->existingAnnotationEvent($user, ['rating.created', 'rating.updated'], $rating->media_type, $rating->media_id)) {
                $summary['existing']++;

                return;
            }

            $this->create($summary, $dryRun, $user, MediaEventType::RatingCreated, $media, [
                'media_type' => $rating->media_type,
                'media_id' => $rating->media_id,
                'media_title' => $this->title($media),
                'rating' => $rating->rating,
            ], MediaEventSource::Import, $rating->updated_at ?: $rating->created_at, $key);
        });

        Note::forUser($user)->orderBy('id')->each(function (Note $note) use ($dryRun, $user, &$summary): void {
            $media = $this->media($user, $note->media_type, $note->media_id);
            if (! $media) {
                $summary['skipped']++;

                return;
            }

            $summary['planned']++;
            $summary['notes']++;
            $key = 'history:note:'.$note->id;
            if ($this->existingAnnotationEvent($user, ['note.created', 'note.updated'], $note->media_type, $note->media_id)) {
                $summary['existing']++;

                return;
            }

            $this->create($summary, $dryRun, $user, MediaEventType::NoteCreated, $media, [
                'media_type' => $note->media_type,
                'media_id' => $note->media_id,
                'media_title' => $this->title($media),
            ], MediaEventSource::Import, $note->created_at, $key);
        });

        $this->backfillImportEvents($user, $dryRun, $summary);

        return $summary;
    }

    /** @param array<string, int> $summary */
    private function backfillImportEvents(User $user, bool $dryRun, array &$summary): void
    {
        $occurredAtValue = EpisodeWatch::forUser($user)->min('watched_at')
            ?: MovieWatch::forUser($user)->min('watched_at')
            ?: $user->created_at
            ?: now();
        $occurredAt = $occurredAtValue instanceof CarbonInterface
            ? $occurredAtValue
            : CarbonImmutable::parse((string) $occurredAtValue);
        $imports = [
            [MediaEventType::MovieImported, 'history:import:movies', ['count' => Movie::forUser($user)->count()]],
            [MediaEventType::ShowImported, 'history:import:shows', ['count' => Show::forUser($user)->count()]],
            [MediaEventType::EpisodeImported, 'history:import:episodes', ['count' => Episode::forUser($user)->count()]],
        ];

        foreach ($imports as [$type, $key, $metadata]) {
            $summary['planned']++;
            $summary['imports']++;
            if (MediaEvent::forUser($user)->where('event_type', $type->value)->exists()) {
                $summary['existing']++;

                continue;
            }

            $this->create($summary, $dryRun, $user, $type, null, $metadata, MediaEventSource::Import, $occurredAt, $key);
        }
    }

    /** @param array<string, int> $summary @param array<string, mixed> $metadata */
    private function create(array &$summary, bool $dryRun, User $user, MediaEventType $type, ?Model $subject, array $metadata, MediaEventSource $source, CarbonInterface $occurredAt, string $key): void
    {
        if ($dryRun) {
            $summary['created']++;

            return;
        }

        $event = $this->events->recordHistorical($user, $type, $subject, $metadata, $source, $occurredAt, $key);
        if ($event?->wasRecentlyCreated) {
            $summary['created']++;
        } elseif ($event) {
            $summary['existing']++;
        } else {
            $summary['skipped']++;
        }
    }

    private function existingWatchEvent(User $user, MediaEventType $type, Model $subject, CarbonInterface $watchedAt): bool
    {
        return MediaEvent::forUser($user)
            ->where('event_type', $type->value)
            ->where('subject_type', $subject::class)
            ->where('subject_id', $subject->getKey())
            ->where('metadata->watched_at', $watchedAt->toIso8601String())
            ->exists();
    }

    /** @param list<string> $types */
    private function existingAnnotationEvent(User $user, array $types, string $mediaType, int $mediaId): bool
    {
        return MediaEvent::forUser($user)
            ->whereIn('event_type', $types)
            ->where('metadata->media_type', $mediaType)
            ->where('metadata->media_id', $mediaId)
            ->exists();
    }

    private function media(User $user, string $type, int $id): ?Model
    {
        return match ($type) {
            'movie' => Movie::forUser($user)->find($id),
            'show' => Show::forUser($user)->find($id),
            'episode' => Episode::forUser($user)->find($id),
            default => null,
        };
    }

    private function title(Model $media): string
    {
        return (string) ($media->getAttribute('title') ?: $media->getAttribute('original_title') ?: 'Untitled item');
    }

    private function source(?string $source): MediaEventSource
    {
        return match ($source) {
            'manual' => MediaEventSource::Manual,
            'player' => MediaEventSource::Player,
            'provider' => MediaEventSource::Provider,
            default => MediaEventSource::Import,
        };
    }

    /** @return array{planned:int,created:int,existing:int,skipped:int,movie_watches:int,episode_watches:int,ratings:int,notes:int,imports:int} */
    private function summary(): array
    {
        return ['planned' => 0, 'created' => 0, 'existing' => 0, 'skipped' => 0, 'movie_watches' => 0, 'episode_watches' => 0, 'ratings' => 0, 'notes' => 0, 'imports' => 0];
    }
}
