<?php

namespace App\Services\Concerns;

trait SanitizesMetadata
{
    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    protected function sanitizeMetadata(array $metadata): array
    {
        $blockedFragments = [
            'access_token',
            'api_key',
            'auth',
            'credential',
            'device',
            'ip',
            'password',
            'playbackurl',
            'playback_url',
            'playlist_url',
            'provider_url',
            'refresh_token',
            'secret',
            'stream_url',
            'token',
            'user_agent',
        ];

        return collect($metadata)
            ->reject(function (mixed $value, string $key) use ($blockedFragments): bool {
                $normalized = strtolower($key);

                foreach ($blockedFragments as $fragment) {
                    if (str_contains($normalized, $fragment)) {
                        return true;
                    }
                }

                return false;
            })
            ->map(fn (mixed $value): mixed => is_array($value) ? $this->sanitizeMetadata($value) : $value)
            ->all();
    }
}
