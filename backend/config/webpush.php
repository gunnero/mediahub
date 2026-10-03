<?php

$path = storage_path('app/private/webpush/keys.json');
$keys = is_file($path) ? json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR) : [];

return [
    'public_key' => env('WEBPUSH_PUBLIC_KEY') ?: ($keys['publicKey'] ?? null),
    'private_key' => env('WEBPUSH_PRIVATE_KEY') ?: ($keys['privateKey'] ?? null),
    'subject' => env('WEBPUSH_SUBJECT') ?: env('APP_URL'),
];
