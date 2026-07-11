<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use SimpleXMLElement;
use Throwable;

class ProviderConnectionService
{
    /**
     * @param  array<string, mixed>  $settings
     * @return array{reachable:bool,authenticated:bool,catalogAvailable:bool,epgAvailable:bool,errorCode:string|null}
     */
    public function test(string $providerType, array $settings): array
    {
        try {
            return match ($providerType) {
                'xtream' => $this->testXtream($settings),
                'm3u' => $this->testM3u($settings),
                'xmltv' => $this->testXmltv($settings),
                'manual' => $this->safeTest(true, true, false, false),
                default => $this->safeTest(false, false, false, false, 'unsupported_provider'),
            };
        } catch (RuntimeException $exception) {
            return $this->safeTest(false, false, false, false, $exception->getMessage());
        } catch (Throwable) {
            return $this->safeTest(false, false, false, false, 'provider_unavailable');
        }
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array{items:list<array<string,mixed>>,epgAvailable:bool}
     */
    public function catalog(string $providerType, array $settings): array
    {
        return match ($providerType) {
            'xtream' => $this->xtreamCatalog($settings),
            'm3u' => $this->m3uCatalog($settings),
            'xmltv' => ['items' => [], 'epgAvailable' => $this->xmltvPrograms($settings['xmltv_url'] ?? null) !== []],
            'manual' => ['items' => [], 'epgAvailable' => false],
            default => throw new RuntimeException('unsupported_provider'),
        };
    }

    /** @param array<string, mixed> $settings */
    private function testXtream(array $settings): array
    {
        $payload = $this->xtreamRequest($settings);
        $authenticated = (int) ($payload['user_info']['auth'] ?? 0) === 1;

        return $this->safeTest(true, $authenticated, $authenticated, filled($settings['xmltv_url'] ?? null), $authenticated ? null : 'authentication_failed');
    }

    /** @param array<string, mixed> $settings */
    private function testM3u(array $settings): array
    {
        $body = $this->requestText($this->validatedUrl((string) ($settings['playlist_url'] ?? '')));
        $available = str_contains($body, '#EXTM3U') || str_contains($body, '#EXTINF');

        return $this->safeTest(true, true, $available, filled($settings['xmltv_url'] ?? null), $available ? null : 'catalog_unavailable');
    }

    /** @param array<string, mixed> $settings */
    private function testXmltv(array $settings): array
    {
        $programs = $this->xmltvPrograms($settings['xmltv_url'] ?? null);

        return $this->safeTest(true, true, false, $programs !== [], $programs !== [] ? null : 'epg_unavailable');
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array{items:list<array<string,mixed>>,epgAvailable:bool}
     */
    private function xtreamCatalog(array $settings): array
    {
        $auth = $this->xtreamRequest($settings);

        if ((int) ($auth['user_info']['auth'] ?? 0) !== 1) {
            throw new RuntimeException('authentication_failed');
        }

        $limit = max(1, (int) config('mediahub_providers.sync_limit', 5000));
        $seriesDetailLimit = max(0, (int) config('mediahub_providers.series_detail_limit', 100));
        $liveCategories = $this->categoryMap($this->xtreamRequest($settings, 'get_live_categories'));
        $movieCategories = $this->categoryMap($this->xtreamRequest($settings, 'get_vod_categories'));
        $seriesCategories = $this->categoryMap($this->xtreamRequest($settings, 'get_series_categories'));
        $epg = $this->xmltvPrograms($settings['xmltv_url'] ?? null, (int) ($settings['epg_time_shift'] ?? 0));
        $items = [];

        foreach ($this->rows($this->xtreamRequest($settings, 'get_live_streams')) as $row) {
            if (count($items) >= $limit) {
                break;
            }

            $id = (string) ($row['stream_id'] ?? '');
            if ($id === '') {
                continue;
            }

            $channelId = (string) ($row['epg_channel_id'] ?? '');
            $items[] = $this->catalogItem(
                externalId: 'live:'.$id,
                kind: 'live',
                title: (string) ($row['name'] ?? 'Live channel'),
                category: $liveCategories[(string) ($row['category_id'] ?? '')] ?? 'Live TV',
                playbackLocator: $this->xtreamPlaybackUrl($settings, 'live', $id, (string) ($row['container_extension'] ?? 'ts')),
                posterUrl: $row['stream_icon'] ?? null,
                metadata: [
                    'provider_item_id' => $id,
                    'epg_channel_id' => $channelId ?: null,
                    'epg' => $channelId !== '' ? ($epg[$channelId] ?? null) : null,
                ],
            );
        }

        foreach ($this->rows($this->xtreamRequest($settings, 'get_vod_streams')) as $row) {
            if (count($items) >= $limit) {
                break;
            }

            $id = (string) ($row['stream_id'] ?? '');
            if ($id === '') {
                continue;
            }

            $items[] = $this->catalogItem(
                externalId: 'movie:'.$id,
                kind: 'movie',
                title: (string) ($row['name'] ?? 'Movie'),
                category: $movieCategories[(string) ($row['category_id'] ?? '')] ?? 'Movies',
                playbackLocator: $this->xtreamPlaybackUrl($settings, 'movie', $id, (string) ($row['container_extension'] ?? 'mp4')),
                posterUrl: $row['stream_icon'] ?? null,
                duration: $this->positiveInt($row['duration_secs'] ?? null),
                year: $this->year($row['year'] ?? $row['releaseDate'] ?? null),
                metadata: [
                    'provider_item_id' => $id,
                    'tmdb_id' => $this->positiveInt($row['tmdb_id'] ?? null),
                    'added' => $this->scalarOrNull($row['added'] ?? null),
                ],
            );
        }

        $seriesRows = $this->rows($this->xtreamRequest($settings, 'get_series'));
        foreach ($seriesRows as $index => $row) {
            if (count($items) >= $limit) {
                break;
            }

            $seriesId = (string) ($row['series_id'] ?? '');
            if ($seriesId === '') {
                continue;
            }

            $items[] = $this->catalogItem(
                externalId: 'show:'.$seriesId,
                kind: 'show',
                title: (string) ($row['name'] ?? 'Series'),
                category: $seriesCategories[(string) ($row['category_id'] ?? '')] ?? 'Shows',
                playbackLocator: null,
                posterUrl: $row['cover'] ?? null,
                year: $this->year($row['releaseDate'] ?? null),
                metadata: [
                    'provider_series_id' => $seriesId,
                    'tmdb_id' => $this->positiveInt($row['tmdb_id'] ?? null),
                    'plot' => $this->scalarOrNull($row['plot'] ?? null),
                ],
            );

            if ($index >= $seriesDetailLimit || count($items) >= $limit) {
                continue;
            }

            $seriesInfo = $this->xtreamRequest($settings, 'get_series_info', ['series_id' => $seriesId]);
            foreach ($this->seriesEpisodes($seriesInfo['episodes'] ?? []) as $episode) {
                if (count($items) >= $limit) {
                    break 2;
                }

                $episodeId = (string) ($episode['id'] ?? '');
                if ($episodeId === '') {
                    continue;
                }

                $season = $this->positiveInt($episode['season'] ?? null);
                $number = $this->positiveInt($episode['episode_num'] ?? null);
                $items[] = $this->catalogItem(
                    externalId: 'episode:'.$episodeId,
                    kind: 'episode',
                    title: (string) ($episode['title'] ?? 'Episode'),
                    category: $seriesCategories[(string) ($row['category_id'] ?? '')] ?? 'Shows',
                    playbackLocator: $this->xtreamPlaybackUrl($settings, 'series', $episodeId, (string) ($episode['container_extension'] ?? 'mp4')),
                    posterUrl: $episode['info']['movie_image'] ?? $row['cover'] ?? null,
                    duration: $this->positiveInt($episode['info']['duration_secs'] ?? null),
                    metadata: [
                        'provider_item_id' => $episodeId,
                        'provider_series_id' => $seriesId,
                        'season_number' => $season,
                        'episode_number' => $number,
                        'tmdb_id' => $this->positiveInt($episode['info']['tmdb_id'] ?? null),
                    ],
                );
            }
        }

        return ['items' => $items, 'epgAvailable' => $epg !== []];
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array{items:list<array<string,mixed>>,epgAvailable:bool}
     */
    private function m3uCatalog(array $settings): array
    {
        $body = $this->requestText($this->validatedUrl((string) ($settings['playlist_url'] ?? '')));
        $lines = preg_split('/\R/', $body) ?: [];
        $items = [];
        $pending = null;
        $limit = max(1, (int) config('mediahub_providers.sync_limit', 5000));

        foreach ($lines as $line) {
            $line = trim($line);
            if (str_starts_with($line, '#EXTINF:')) {
                preg_match('/tvg-id="([^"]*)"/i', $line, $tvgId);
                preg_match('/tvg-logo="([^"]*)"/i', $line, $logo);
                preg_match('/group-title="([^"]*)"/i', $line, $group);
                $title = trim((string) str($line)->afterLast(','));
                $pending = [
                    'external_id' => filled($tvgId[1] ?? null) ? 'm3u:'.$tvgId[1] : 'm3u:'.sha1($line),
                    'title' => $title ?: 'Channel',
                    'poster' => $logo[1] ?? null,
                    'category' => $group[1] ?? 'Live TV',
                    'epg_channel_id' => $tvgId[1] ?? null,
                ];

                continue;
            }

            if ($pending && $line !== '' && ! str_starts_with($line, '#')) {
                $items[] = $this->catalogItem(
                    externalId: $pending['external_id'],
                    kind: 'live',
                    title: $pending['title'],
                    category: $pending['category'],
                    playbackLocator: $this->validatedUrl($line),
                    posterUrl: $pending['poster'],
                    metadata: ['epg_channel_id' => $pending['epg_channel_id']],
                );
                $pending = null;

                if (count($items) >= $limit) {
                    break;
                }
            }
        }

        $epg = $this->xmltvPrograms($settings['xmltv_url'] ?? null, (int) ($settings['epg_time_shift'] ?? 0));
        foreach ($items as &$item) {
            $channelId = $item['metadata']['epg_channel_id'] ?? null;
            if ($channelId && isset($epg[$channelId])) {
                $item['metadata']['epg'] = $epg[$channelId];
            }
        }

        return ['items' => $items, 'epgAvailable' => $epg !== []];
    }

    /** @return array<string, mixed> */
    private function xtreamRequest(array $settings, ?string $action = null, array $extra = []): array
    {
        $baseUrl = rtrim($this->validatedUrl((string) ($settings['base_url'] ?? '')), '/');
        $username = (string) ($settings['username'] ?? '');
        $password = (string) ($settings['password'] ?? '');

        if ($username === '' || $password === '') {
            throw new RuntimeException('credentials_missing');
        }

        return $this->requestJson($baseUrl.'/player_api.php', array_filter([
            'username' => $username,
            'password' => $password,
            'action' => $action,
            ...$extra,
        ], fn (mixed $value): bool => $value !== null && $value !== ''));
    }

    private function xtreamPlaybackUrl(array $settings, string $kind, string $id, string $extension): string
    {
        $baseUrl = rtrim($this->validatedUrl((string) ($settings['base_url'] ?? '')), '/');
        $username = rawurlencode((string) ($settings['username'] ?? ''));
        $password = rawurlencode((string) ($settings['password'] ?? ''));
        $extension = preg_replace('/[^a-z0-9]/i', '', $extension) ?: ($kind === 'live' ? 'ts' : 'mp4');

        return $baseUrl.'/'.$kind.'/'.$username.'/'.$password.'/'.rawurlencode($id).'.'.$extension;
    }

    /** @return array<string, mixed> */
    private function requestJson(string $url, array $query = []): array
    {
        $response = $this->request($url, $query);
        $payload = $response->json();

        if (! is_array($payload)) {
            throw new RuntimeException('provider_invalid_response');
        }

        return $payload;
    }

    private function requestText(string $url): string
    {
        return $this->request($url)->body();
    }

    private function request(string $url, array $query = []): Response
    {
        try {
            $request = Http::timeout(max(1, (int) config('mediahub_providers.timeout', 20)))
                ->retry(2, 200, throw: false)
                ->accept('*/*');
            $response = $query === []
                ? $request->get($url)
                : $request->get($url, $query);
        } catch (Throwable) {
            throw new RuntimeException('provider_unreachable');
        }

        if (! $response->successful()) {
            throw new RuntimeException('provider_http_'.$response->status());
        }

        $maxBytes = max(1024, (int) config('mediahub_providers.max_response_bytes', 26214400));
        $contentLength = (int) ($response->header('Content-Length') ?? 0);
        if ($contentLength > $maxBytes || strlen($response->body()) > $maxBytes) {
            throw new RuntimeException('provider_response_too_large');
        }

        return $response;
    }

    /** @return array<string, array<string, mixed>> */
    private function xmltvPrograms(mixed $url, int $timeShiftHours = 0): array
    {
        if (! filled($url)) {
            return [];
        }

        $body = $this->requestText($this->validatedUrl((string) $url));

        try {
            $xml = new SimpleXMLElement($body, LIBXML_NONET | LIBXML_NOCDATA);
        } catch (Throwable) {
            return [];
        }

        $now = now()->addHours($timeShiftHours);
        $programs = [];

        foreach ($xml->programme as $programme) {
            $channel = (string) $programme['channel'];
            $start = $this->xmltvDate((string) $programme['start']);
            $stop = $this->xmltvDate((string) $programme['stop']);
            if ($channel === '' || ! $start || ! $stop) {
                continue;
            }

            $entry = [
                'title' => trim((string) $programme->title) ?: 'Program',
                'start' => $start->toIso8601String(),
                'stop' => $stop->toIso8601String(),
            ];

            if ($start->lte($now) && $stop->gt($now)) {
                $programs[$channel]['current'] = $entry;
            } elseif ($start->gt($now) && ! isset($programs[$channel]['next'])) {
                $programs[$channel]['next'] = $entry;
            }
        }

        return $programs;
    }

    private function xmltvDate(string $value): ?Carbon
    {
        if (! preg_match('/^(\d{14})(?:\s+([+-]\d{4}))?/', trim($value), $matches)) {
            return null;
        }

        try {
            return Carbon::createFromFormat('YmdHis O', $matches[1].' '.($matches[2] ?? '+0000'));
        } catch (Throwable) {
            return null;
        }
    }

    private function validatedUrl(string $url): string
    {
        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            throw new RuntimeException('provider_url_invalid');
        }

        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (! in_array($scheme, ['http', 'https'], true) || $host === '' || $host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')) {
            throw new RuntimeException('provider_url_not_allowed');
        }

        if (filter_var($host, FILTER_VALIDATE_IP) && ! filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            throw new RuntimeException('provider_url_not_allowed');
        }

        return $url;
    }

    /** @return list<array<string, mixed>> */
    private function rows(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_array')) : [];
    }

    /** @return array<string, string> */
    private function categoryMap(array $rows): array
    {
        $map = [];
        foreach ($this->rows($rows) as $row) {
            if (filled($row['category_id'] ?? null)) {
                $map[(string) $row['category_id']] = (string) ($row['category_name'] ?? 'Uncategorized');
            }
        }

        return $map;
    }

    /** @return list<array<string, mixed>> */
    private function seriesEpisodes(mixed $seasons): array
    {
        if (! is_array($seasons)) {
            return [];
        }

        return collect($seasons)->flatMap(fn (mixed $episodes) => is_array($episodes) ? $episodes : [])->filter(fn (mixed $episode): bool => is_array($episode))->values()->all();
    }

    /** @return array<string, mixed> */
    private function catalogItem(string $externalId, string $kind, string $title, ?string $category, ?string $playbackLocator, mixed $posterUrl = null, ?int $duration = null, ?int $year = null, array $metadata = []): array
    {
        return [
            'external_id' => $externalId,
            'kind' => $kind,
            'title' => trim($title) ?: 'Untitled item',
            'category' => filled($category) ? trim((string) $category) : null,
            'playback_locator' => $playbackLocator,
            'poster_url' => filled($posterUrl) ? (string) $posterUrl : null,
            'duration_seconds' => $duration,
            'release_year' => $year,
            'metadata' => array_filter($metadata, fn (mixed $value): bool => $value !== null && $value !== ''),
        ];
    }

    private function positiveInt(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    private function year(mixed $value): ?int
    {
        return preg_match('/\b(19|20)\d{2}\b/', (string) $value, $matches) ? (int) $matches[0] : null;
    }

    private function scalarOrNull(mixed $value): string|int|float|bool|null
    {
        return is_scalar($value) ? $value : null;
    }

    /** @return array{reachable:bool,authenticated:bool,catalogAvailable:bool,epgAvailable:bool,errorCode:string|null} */
    private function safeTest(bool $reachable, bool $authenticated, bool $catalog, bool $epg, ?string $error = null): array
    {
        return [
            'reachable' => $reachable,
            'authenticated' => $authenticated,
            'catalogAvailable' => $catalog,
            'epgAvailable' => $epg,
            'errorCode' => $error,
        ];
    }
}
