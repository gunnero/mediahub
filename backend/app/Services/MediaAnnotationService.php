<?php

namespace App\Services;

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
    ) {}

    public function rate(User $user, string $mediaType, int $mediaId, int $rating): Rating
    {
        $this->assertOwnedMedia($user, $mediaType, $mediaId);

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

        return $record;
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
}
