<?php

namespace App\Services;

use App\Services\Concerns\SanitizesMetadata;

class SafeAIMatchingPayloadService
{
    use SanitizesMetadata;

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function sanitize(array $payload): array
    {
        return $this->sanitizeMetadata($payload);
    }
}
