<?php

namespace App\Services;

use App\Models\MediaList;
use App\Models\Movie;
use App\Models\Show;
use App\Models\User;

class SmartCollectionService
{
    public function items(User $user, MediaList $list, int $limit = 100): array
    {
        $rules = $list->rules;
        $type = $rules['type'] ?? 'movie';
        $query = ($type === 'show' ? Show::query() : Movie::query())->forUser($user);
        app(PersonalLibraryService::class)->apply($query, $user, $type, $rules);
        $metadata = app(MediaMetadataService::class);
        $count = (clone $query)->count();
        $items = $query->orderBy('title')->limit($limit)->get()->map(fn ($media) => [
            'id' => $type.'-'.$media->id, 'mediaType' => $type, 'mediaId' => $media->id,
            'title' => $media->title, 'poster' => $metadata->imageUrl($media->poster_path) ?: $media->poster_url,
            'year' => ($media->release_date ?? $media->first_air_date)?->format('Y'),
        ])->all();

        return ['items' => $items, 'itemsCount' => $count, 'truncated' => $count > $limit];
    }
}
