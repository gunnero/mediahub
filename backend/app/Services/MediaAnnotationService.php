<?php

namespace App\Services;

use App\Enums\MediaEventSource;
use App\Enums\MediaEventType;
use App\Models\Episode;
use App\Models\Movie;
use App\Models\Note;
use App\Models\Rating;
use App\Models\Show;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class MediaAnnotationService
{
    public function __construct(
        private readonly AnalyticsService $analytics,
        private readonly AuditLogService $auditLogs,
        private readonly MediaEventService $mediaEvents,
    ) {}

    public function rate(User $user, string $mediaType, int $mediaId, int $rating): Rating
    {
        $this->assertOwnedMedia($user, $mediaType, $mediaId);

        $existing = Rating::forUser($user)->forMedia($mediaType, $mediaId)->first();
        $record = Rating::updateOrCreate([
            'user_id' => $user->id,
            'media_type' => $mediaType,
            'media_id' => $mediaId,
        ], [
            'rating' => $rating,
        ]);

        $metadata = [
            'media_type' => $mediaType,
            'media_id' => $mediaId,
            'rating' => $rating,
        ];

        $this->analytics->record('media.rating.saved', $user, $metadata);
        $this->auditLogs->record('media.rating.saved', $user, $record, $user, $metadata);
        $this->mediaEvents->record(
            $user,
            $existing ? MediaEventType::RatingUpdated : MediaEventType::RatingCreated,
            $record,
            [
                ...$metadata,
                'media_title' => $this->mediaTitle($user, $mediaType, $mediaId),
            ],
            MediaEventSource::Manual,
        );

        return $record;
    }

    public function addNote(User $user, string $mediaType, int $mediaId, string $body): Note
    {
        $this->assertOwnedMedia($user, $mediaType, $mediaId);

        $record = Note::create([
            'user_id' => $user->id,
            'media_type' => $mediaType,
            'media_id' => $mediaId,
            'body' => $body,
        ]);

        $metadata = [
            'media_type' => $mediaType,
            'media_id' => $mediaId,
        ];

        $this->analytics->record('media.note.created', $user, $metadata);
        $this->auditLogs->record('media.note.created', $user, $record, $user, $metadata);
        $this->mediaEvents->record($user, MediaEventType::NoteCreated, $record, [
            ...$metadata,
            'media_title' => $this->mediaTitle($user, $mediaType, $mediaId),
        ], MediaEventSource::Manual);

        return $record;
    }

    public function clearRating(User $user, string $mediaType, int $mediaId): void
    {
        $this->assertOwnedMedia($user, $mediaType, $mediaId);

        $rating = Rating::forUser($user)->forMedia($mediaType, $mediaId)->first();

        if (! $rating) {
            return;
        }

        $metadata = [
            'media_type' => $mediaType,
            'media_id' => $mediaId,
        ];

        $this->analytics->record('media.rating.cleared', $user, $metadata);
        $this->auditLogs->record('media.rating.cleared', $user, $rating, $user, $metadata);
        $this->mediaEvents->record($user, MediaEventType::RatingDeleted, $rating, [
            ...$metadata,
            'media_title' => $this->mediaTitle($user, $mediaType, $mediaId),
        ], MediaEventSource::Manual);

        $rating->delete();
    }

    public function updateNote(User $user, Note $note, string $body): Note
    {
        $this->assertOwnedNote($user, $note);
        $this->assertOwnedMedia($user, $note->media_type, $note->media_id);

        $note->forceFill(['body' => $body])->save();

        $metadata = [
            'media_type' => $note->media_type,
            'media_id' => $note->media_id,
        ];

        $this->analytics->record('media.note.updated', $user, $metadata);
        $this->auditLogs->record('media.note.updated', $user, $note, $user, $metadata);
        $this->mediaEvents->record($user, MediaEventType::NoteUpdated, $note, [
            ...$metadata,
            'media_title' => $this->mediaTitle($user, $note->media_type, $note->media_id),
        ], MediaEventSource::Manual);

        return $note->refresh();
    }

    public function deleteNote(User $user, Note $note): void
    {
        $this->assertOwnedNote($user, $note);
        $this->assertOwnedMedia($user, $note->media_type, $note->media_id);

        $metadata = [
            'media_type' => $note->media_type,
            'media_id' => $note->media_id,
        ];

        $this->analytics->record('media.note.deleted', $user, $metadata);
        $this->auditLogs->record('media.note.deleted', $user, $note, $user, $metadata);
        $this->mediaEvents->record($user, MediaEventType::NoteDeleted, $note, [
            ...$metadata,
            'media_title' => $this->mediaTitle($user, $note->media_type, $note->media_id),
        ], MediaEventSource::Manual);

        $note->delete();
    }

    private function assertOwnedMedia(User $user, string $mediaType, int $mediaId): void
    {
        $exists = match ($mediaType) {
            'movie' => Movie::forUser($user)->whereKey($mediaId)->exists(),
            'show' => Show::forUser($user)->whereKey($mediaId)->exists(),
            'episode' => Episode::forUser($user)->whereKey($mediaId)->exists(),
            default => false,
        };

        if (! $exists) {
            throw new ModelNotFoundException;
        }
    }

    private function assertOwnedNote(User $user, Note $note): void
    {
        if ($note->user_id !== $user->id) {
            throw new ModelNotFoundException;
        }
    }

    private function mediaTitle(User $user, string $mediaType, int $mediaId): ?string
    {
        return match ($mediaType) {
            'movie' => Movie::forUser($user)->whereKey($mediaId)->value('title'),
            'show' => Show::forUser($user)->whereKey($mediaId)->value('title'),
            'episode' => Episode::forUser($user)->whereKey($mediaId)->value('title'),
            default => null,
        };
    }
}
