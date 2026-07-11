<?php

namespace App\Services;

use App\Enums\MediaEventSource;
use App\Enums\MediaEventType;
use App\Enums\ProviderSyncStatus;
use App\Models\PlaybackSource;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class ProviderService
{
    public function __construct(
        private readonly ProviderConnectionService $connection,
        private readonly PlaybackLibraryService $playback,
        private readonly AuditLogService $auditLogs,
        private readonly MediaEventService $events,
    ) {}

    /** @return list<array<string, mixed>> */
    public function list(User $user): array
    {
        return PlaybackSource::forUser($user)
            ->withCount(['items', 'items as active_items_count' => fn ($query) => $query->where('status', 'available')])
            ->latest('updated_at')
            ->get()
            ->map(fn (PlaybackSource $source): array => $this->summary($source))
            ->values()
            ->all();
    }

    /** @param array<string, mixed> $data */
    public function create(User $user, array $data): PlaybackSource
    {
        $source = PlaybackSource::create([
            'user_id' => $user->id,
            'name' => trim((string) $data['name']),
            'provider_type' => $data['provider_type'],
            'status' => ($data['enabled'] ?? true) ? 'active' : 'disabled',
            'sync_status' => ProviderSyncStatus::NeverSynced->value,
            'settings' => $this->settings($data),
            'metadata' => [
                'created_from' => 'settings-ui',
                'legal_confirmed_at' => now()->toIso8601String(),
                'epg_available' => false,
                'sync_summary' => null,
            ],
        ]);

        $this->auditLogs->record('playback_source.created', $user, $source, $user, [
            'provider_type' => $source->provider_type,
            'status' => $source->status,
        ]);
        $this->events->record($user, MediaEventType::ProviderCreated, $source, [
            'title' => $source->name,
            'provider_type' => $source->provider_type,
            'status' => $source->status,
        ], MediaEventSource::Provider);

        return $source->refresh()->loadCount(['items', 'items as active_items_count' => fn ($query) => $query->where('status', 'available')]);
    }

    /** @param array<string, mixed> $data */
    public function update(User $user, PlaybackSource $source, array $data): PlaybackSource
    {
        $this->assertOwned($user, $source);
        $settings = $this->settings($data, $source->settings ?? []);
        $previousStatus = $source->status;

        $source->forceFill([
            'name' => isset($data['name']) ? trim((string) $data['name']) : $source->name,
            'status' => array_key_exists('enabled', $data) ? ($data['enabled'] ? 'active' : 'disabled') : $source->status,
            'settings' => $settings,
        ])->save();

        $this->auditLogs->record('playback_source.updated', $user, $source, $user, [
            'provider_type' => $source->provider_type,
            'status' => $source->status,
        ]);
        $this->events->record($user, MediaEventType::ProviderUpdated, $source, [
            'title' => $source->name,
            'provider_type' => $source->provider_type,
            'status' => $source->status,
        ], MediaEventSource::Provider);

        if ($previousStatus !== 'disabled' && $source->status === 'disabled') {
            $this->events->record($user, MediaEventType::ProviderDisabled, $source, [
                'title' => $source->name,
                'provider_type' => $source->provider_type,
            ], MediaEventSource::Provider);
        }

        return $source->refresh()->loadCount(['items', 'items as active_items_count' => fn ($query) => $query->where('status', 'available')]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{reachable:bool,authenticated:bool,catalogAvailable:bool,epgAvailable:bool,errorCode:string|null}
     */
    public function test(User $user, array $data): array
    {
        $source = null;
        if (filled($data['provider_id'] ?? null)) {
            $source = PlaybackSource::forUser($user)->findOrFail((int) $data['provider_id']);
        }

        $providerType = (string) ($data['provider_type'] ?? $source?->provider_type ?? 'manual');
        $settings = $this->settings($data, $source?->settings ?? []);
        $result = $this->connection->test($providerType, $settings);

        $this->auditLogs->record('playback_source.tested', $user, $source, $user, [
            'provider_type' => $providerType,
            'reachable' => $result['reachable'],
            'authenticated' => $result['authenticated'],
            'catalog_available' => $result['catalogAvailable'],
            'epg_available' => $result['epgAvailable'],
            'error_code' => $result['errorCode'],
        ]);
        $this->events->record($user, MediaEventType::ProviderTested, $source, [
            'title' => $source?->name ?? 'New provider',
            'provider_type' => $providerType,
            'reachable' => $result['reachable'],
            'authenticated' => $result['authenticated'],
            'catalog_available' => $result['catalogAvailable'],
            'epg_available' => $result['epgAvailable'],
            'error_code' => $result['errorCode'],
        ], MediaEventSource::Provider);

        return $result;
    }

    public function delete(User $user, PlaybackSource $source): void
    {
        $this->assertOwned($user, $source);
        $this->playback->deleteSource($user, $source);
    }

    /** @return array<string, mixed> */
    public function summary(PlaybackSource $source): array
    {
        $settings = $source->settings ?? [];
        $metadata = $source->metadata ?? [];

        return [
            'id' => $source->id,
            'name' => $source->name,
            'providerType' => $source->provider_type,
            'status' => $source->status,
            'enabled' => $source->status === 'active',
            'syncStatus' => ProviderSyncStatus::normalize($source->sync_status),
            'lastSyncError' => $source->last_sync_error,
            'lastSyncedAt' => $source->last_synced_at?->toIso8601String(),
            'itemsCount' => (int) ($source->items_count ?? $source->items()->count()),
            'activeItemsCount' => (int) ($source->active_items_count ?? $source->items()->where('status', 'available')->count()),
            'credentialsConfigured' => filled($settings['username'] ?? null) && filled($settings['password'] ?? null),
            'serverConfigured' => filled($settings['base_url'] ?? null) || filled($settings['playlist_url'] ?? null),
            'xmltvConfigured' => filled($settings['xmltv_url'] ?? null),
            'refreshFrequency' => $settings['refresh_frequency'] ?? 'manual',
            'epgTimeShift' => (int) ($settings['epg_time_shift'] ?? 0),
            'epgAvailable' => (bool) ($metadata['epg_available'] ?? false),
            'syncSummary' => is_array($metadata['sync_summary'] ?? null) ? $metadata['sync_summary'] : null,
        ];
    }

    /** @param array<string, mixed> $data @param array<string, mixed> $existing @return array<string, mixed> */
    private function settings(array $data, array $existing = []): array
    {
        $mapping = [
            'base_url' => 'base_url',
            'username' => 'username',
            'password' => 'password',
            'playlist_url' => 'playlist_url',
            'xmltv_url' => 'xmltv_url',
            'epg_time_shift' => 'epg_time_shift',
            'refresh_frequency' => 'refresh_frequency',
        ];

        foreach ($mapping as $input => $key) {
            if (array_key_exists($input, $data) && $data[$input] !== null && $data[$input] !== '') {
                $existing[$key] = is_string($data[$input]) ? trim($data[$input]) : $data[$input];
            }
        }

        $existing['refresh_frequency'] ??= 'manual';
        $existing['epg_time_shift'] ??= 0;

        return $existing;
    }

    private function assertOwned(User $user, PlaybackSource $source): void
    {
        if ($source->user_id !== $user->id) {
            throw new ModelNotFoundException;
        }
    }
}
