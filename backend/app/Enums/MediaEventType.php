<?php

namespace App\Enums;

enum MediaEventType: string
{
    case MovieImported = 'movie.imported';
    case ShowImported = 'show.imported';
    case EpisodeImported = 'episode.imported';
    case MovieWatched = 'movie.watched';
    case EpisodeWatched = 'episode.watched';
    case MovieUnwatched = 'movie.unwatched';
    case EpisodeUnwatched = 'episode.unwatched';
    case RatingCreated = 'rating.created';
    case RatingUpdated = 'rating.updated';
    case RatingDeleted = 'rating.deleted';
    case NoteCreated = 'note.created';
    case NoteUpdated = 'note.updated';
    case NoteDeleted = 'note.deleted';
    case ProviderCreated = 'provider.created';
    case ProviderDisabled = 'provider.disabled';
    case ProviderDeleted = 'provider.deleted';
    case ProviderItemCreated = 'provider.item.created';
    case ProviderItemLinked = 'provider.item.linked';
    case ProviderItemUnlinked = 'provider.item.unlinked';
    case PlaybackStarted = 'playback.started';
    case PlaybackProgressed = 'playback.progressed';
    case PlaybackCompleted = 'playback.completed';
    case MetadataEnriched = 'metadata.enriched';
    case BackupCreated = 'backup.created';
    case RestoreCompleted = 'restore.completed';
}
