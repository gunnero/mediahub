<?php

return [
    'enabled' => env('KALVERI_AI_ENABLED', false),
    'base_url' => env('KALVERI_AI_BASE_URL'),
    'api_key' => env('KALVERI_AI_API_KEY'),
    'timeout' => (int) env('KALVERI_AI_TIMEOUT', 20),
    'paths' => [
        'provider_item_match' => '/api/v1/media/match-provider-item',
        'metadata_review_episode_match' => '/api/v1/media/match-metadata-review-episode',
    ],
];
