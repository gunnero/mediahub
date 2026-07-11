<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class KalveriAIClient
{
    public function __construct(
        private readonly SafeAIMatchingPayloadService $payloads,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('kalveri_ai.enabled') && filled(config('kalveri_ai.base_url')) && filled(config('kalveri_ai.api_key'));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function matchProviderItem(array $payload): array
    {
        return $this->post((string) config('kalveri_ai.paths.provider_item_match'), $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function matchMetadataReviewEpisode(array $payload): array
    {
        return $this->post((string) config('kalveri_ai.paths.metadata_review_episode_match'), $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function post(string $path, array $payload): array
    {
        if (! $this->enabled()) {
            return [
                'status' => 'disabled',
                'suggestion' => null,
                'error' => 'kalveri_ai_disabled',
            ];
        }

        try {
            $response = Http::timeout(max(1, (int) config('kalveri_ai.timeout', 20)))
                ->acceptJson()
                ->asJson()
                ->withToken((string) config('kalveri_ai.api_key'))
                ->post(rtrim((string) config('kalveri_ai.base_url'), '/').'/'.ltrim($path, '/'), $this->payloads->sanitize($payload));
        } catch (Throwable) {
            Log::warning('Kalveri AI request failed.', ['path' => $path]);

            return [
                'status' => 'failed',
                'suggestion' => null,
                'error' => 'kalveri_ai_unavailable',
            ];
        }

        if (! $response->ok()) {
            Log::warning('Kalveri AI request returned non-success status.', [
                'path' => $path,
                'status' => $response->status(),
            ]);

            return [
                'status' => 'failed',
                'suggestion' => null,
                'error' => 'kalveri_ai_http_'.$response->status(),
            ];
        }

        $json = $response->json();

        return is_array($json)
            ? $this->payloads->sanitize($json)
            : [
                'status' => 'failed',
                'suggestion' => null,
                'error' => 'kalveri_ai_invalid_json',
            ];
    }
}
