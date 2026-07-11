<?php

namespace App\Services;

use App\Enums\MediaEventSource;
use App\Enums\MediaEventType;
use App\Enums\ProviderSyncStatus;
use App\Models\Episode;
use App\Models\MediaLink;
use App\Models\Movie;
use App\Models\PlaybackSource;
use App\Models\PlaybackSourceItem;
use App\Models\Show;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class ProviderCatalogService
{
    public function __construct(
        private readonly ProviderConnectionService $connection,
        private readonly AuditLogService $auditLogs,
        private readonly MediaEventService $events,
    ) {}

    /** @return array{created:int,updated:int,deactivated:int,available:int,suggested:int,liveItems:int,movieItems:int,seriesItems:int,episodeItems:int,categories:int,epgRows:int,failedEndpoints:int,safeFailureReason:string|null,epgAvailable:bool} */
    public function refresh(User $user, PlaybackSource $source): array
    {
        $this->assertOwned($user, $source);

        if ($source->status !== 'active') {
            throw new RuntimeException('provider_disabled');
        }

        $source->forceFill(['sync_status' => ProviderSyncStatus::Syncing->value, 'last_sync_error' => null])->save();
        $this->events->record($user, MediaEventType::ProviderRefreshStarted, $source, [
            'title' => $source->name,
            'provider_type' => $source->provider_type,
        ], MediaEventSource::Provider);

        try {
            $catalog = $this->connection->catalog($source->provider_type, $source->settings ?? []);
            $summary = DB::transaction(fn (): array => $this->persist($user, $source, $catalog));

            $metadata = $source->metadata ?? [];
            $metadata['epg_available'] = $summary['epgAvailable'];
            $metadata['sync_summary'] = $this->safeSummary($summary);
            $source->forceFill([
                'sync_status' => $summary['failedEndpoints'] > 0
                    ? ProviderSyncStatus::CompletedWithWarnings->value
                    : ProviderSyncStatus::Completed->value,
                'last_sync_error' => null,
                'last_synced_at' => now(),
                'metadata' => $metadata,
            ])->save();

            $this->auditLogs->record('playback_source.refresh_completed', $user, $source, $user, $metadata['sync_summary']);
            $this->events->record($user, MediaEventType::ProviderRefreshCompleted, $source, [
                'title' => $source->name,
                'provider_type' => $source->provider_type,
                ...$metadata['sync_summary'],
                'epg_available' => $summary['epgAvailable'],
            ], MediaEventSource::Provider);

            return $summary;
        } catch (Throwable $exception) {
            $safeCode = $exception instanceof RuntimeException ? $exception->getMessage() : 'provider_refresh_failed';
            if (! preg_match('/^[a-z0-9_]+$/', $safeCode)) {
                $safeCode = 'provider_refresh_failed';
            }

            $failureSummary = $this->emptySummary(1, $safeCode);
            $metadata = $source->metadata ?? [];
            $metadata['sync_summary'] = $this->safeSummary($failureSummary);
            $source->forceFill([
                'sync_status' => ProviderSyncStatus::Failed->value,
                'last_sync_error' => $safeCode,
                'last_synced_at' => now(),
                'metadata' => $metadata,
            ])->save();
            $this->auditLogs->record('playback_source.refresh_failed', $user, $source, $user, [
                'provider_type' => $source->provider_type,
                'error_code' => $safeCode,
            ]);
            $this->events->record($user, MediaEventType::ProviderRefreshFailed, $source, [
                'title' => $source->name,
                'provider_type' => $source->provider_type,
                'error_code' => $safeCode,
            ], MediaEventSource::Provider);

            throw new RuntimeException($safeCode);
        }
    }

    /**
     * @param  array{items:list<array<string,mixed>>,epgAvailable:bool}  $catalog
     * @return array{created:int,updated:int,deactivated:int,available:int,suggested:int,liveItems:int,movieItems:int,seriesItems:int,episodeItems:int,categories:int,epgRows:int,failedEndpoints:int,safeFailureReason:string|null,epgAvailable:bool}
     */
    private function persist(User $user, PlaybackSource $source, array $catalog): array
    {
        $summary = $this->emptySummary();
        $summary['epgAvailable'] = (bool) $catalog['epgAvailable'];
        $seen = [];
        $categories = [];

        foreach ($catalog['items'] as $row) {
            $externalId = (string) ($row['external_id'] ?? '');
            if ($externalId === '') {
                continue;
            }

            $item = PlaybackSourceItem::forUser($user)
                ->where('playback_source_id', $source->id)
                ->where('external_id', $externalId)
                ->first();
            $isNew = ! $item;
            $item ??= new PlaybackSourceItem([
                'user_id' => $user->id,
                'playback_source_id' => $source->id,
                'external_id' => $externalId,
            ]);
            $existingMetadata = $item->metadata ?? [];
            $catalogMetadata = is_array($row['metadata'] ?? null) ? $row['metadata'] : [];
            $suggestion = $this->suggestion($user, (string) ($row['kind'] ?? ''), (string) ($row['title'] ?? ''), $catalogMetadata);
            $hasLink = $item->exists && MediaLink::forUser($user)->where('playback_source_item_id', $item->id)->exists();
            $matchStatus = $hasLink ? 'linked' : ($suggestion ? 'suggested' : 'needs_review');

            $item->forceFill([
                'kind' => (string) ($row['kind'] ?? 'movie'),
                'title' => (string) ($row['title'] ?? 'Untitled item'),
                'status' => 'available',
                'stream_url' => $row['playback_locator'] ?? null,
                'stream_url_hash' => filled($row['playback_locator'] ?? null) ? hash('sha256', (string) $row['playback_locator']) : null,
                'category' => $row['category'] ?? null,
                'poster_url' => $row['poster_url'] ?? null,
                'duration_seconds' => $row['duration_seconds'] ?? null,
                'release_year' => $row['release_year'] ?? null,
                'match_status' => $matchStatus,
                'metadata' => [
                    ...$existingMetadata,
                    ...$catalogMetadata,
                    'link_suggestion' => $suggestion,
                ],
                'last_seen_at' => now(),
                'catalog_synced_at' => now(),
            ])->save();

            $seen[] = $externalId;
            $summary[$isNew ? 'created' : 'updated']++;
            $summary['available']++;
            $summary['suggested'] += $suggestion && ! $hasLink ? 1 : 0;
            $kindCount = match ($item->kind) {
                'live' => 'liveItems',
                'movie' => 'movieItems',
                'show' => 'seriesItems',
                'episode' => 'episodeItems',
                default => null,
            };
            if ($kindCount) {
                $summary[$kindCount]++;
            }
            if (filled($item->category)) {
                $categories[$item->category] = true;
            }
            $epg = $catalogMetadata['epg'] ?? null;
            if (is_array($epg)) {
                $summary['epgRows'] += (int) isset($epg['current']) + (int) isset($epg['next']);
            }
        }

        $summary['categories'] = count($categories);

        if (! in_array($source->provider_type, ['manual', 'xmltv'], true)) {
            $deactivated = PlaybackSourceItem::forUser($user)
                ->where('playback_source_id', $source->id)
                ->when($seen !== [], fn ($query) => $query->whereNotIn('external_id', $seen))
                ->when($seen === [], fn ($query) => $query->whereRaw('1 = 1'))
                ->where('status', 'available')
                ->update(['status' => 'unavailable']);
            $summary['deactivated'] = $deactivated;
        }

        return $summary;
    }

    /** @return array{created:int,updated:int,deactivated:int,available:int,suggested:int,liveItems:int,movieItems:int,seriesItems:int,episodeItems:int,categories:int,epgRows:int,failedEndpoints:int,safeFailureReason:string|null,epgAvailable:bool} */
    private function emptySummary(int $failedEndpoints = 0, ?string $safeFailureReason = null): array
    {
        return [
            'created' => 0,
            'updated' => 0,
            'deactivated' => 0,
            'available' => 0,
            'suggested' => 0,
            'liveItems' => 0,
            'movieItems' => 0,
            'seriesItems' => 0,
            'episodeItems' => 0,
            'categories' => 0,
            'epgRows' => 0,
            'failedEndpoints' => $failedEndpoints,
            'safeFailureReason' => $safeFailureReason,
            'epgAvailable' => false,
        ];
    }

    /** @param array<string, mixed> $summary @return array<string, int|string|null> */
    private function safeSummary(array $summary): array
    {
        return collect($summary)
            ->except('epgAvailable')
            ->map(fn (mixed $value): int|string|null => is_int($value) || is_string($value) || $value === null ? $value : null)
            ->all();
    }

    /** @param array<string, mixed> $metadata @return array<string, mixed>|null */
    private function suggestion(User $user, string $kind, string $title, array $metadata): ?array
    {
        $tmdbId = is_numeric($metadata['tmdb_id'] ?? null) ? (int) $metadata['tmdb_id'] : null;
        $model = match ($kind) {
            'movie' => Movie::class,
            'show' => Show::class,
            'episode' => Episode::class,
            default => null,
        };

        if (! $model) {
            return null;
        }

        $candidateQuery = $model::forUser($user);
        if ($tmdbId) {
            $candidate = $candidateQuery->where('tmdb_id', $tmdbId)->first();
        } else {
            $candidateQuery->whereRaw('LOWER(title) = ?', [mb_strtolower(trim($title))]);
            if ($kind === 'episode') {
                $season = is_numeric($metadata['season_number'] ?? null) ? (int) $metadata['season_number'] : null;
                $episode = is_numeric($metadata['episode_number'] ?? null) ? (int) $metadata['episode_number'] : null;
                if (! $season || ! $episode) {
                    return null;
                }
                $candidateQuery->where('season_number', $season)->where('episode_number', $episode);
            }
            $candidate = $candidateQuery->first();
        }

        if (! $candidate) {
            return null;
        }

        return [
            'media_type' => $kind,
            'candidate_id' => $candidate->id,
            'title' => $candidate->title,
            'confidence' => $tmdbId ? 1.0 : 0.95,
            'reason' => $tmdbId ? 'Exact TMDB identity.' : 'Exact normalized title.',
            'requires_confirmation' => true,
        ];
    }

    private function assertOwned(User $user, PlaybackSource $source): void
    {
        if ($source->user_id !== $user->id) {
            throw new ModelNotFoundException;
        }
    }
}
