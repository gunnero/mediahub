<?php

namespace App\Services;

use App\Models\MediaList;
use App\Models\MediaListItem;
use App\Models\Movie;
use App\Models\Show;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

class MediaListService
{
    public function __construct(private readonly MediaMetadataService $metadata) {}

    /** @return list<array<string, mixed>> */
    public function all(User $user): array
    {
        return MediaList::forUser($user)
            ->with(['items' => fn ($query) => $query->forUser($user)])
            ->withCount(['items' => fn ($query) => $query->forUser($user)])
            ->latest('updated_at')->get()
            ->map(fn (MediaList $list): array => $this->summary($user, $list))->all();
    }

    public function create(User $user, array $data): MediaList
    {
        return MediaList::create([
            'user_id' => $user->id,
            'name' => trim((string) $data['name']),
            'description' => filled($data['description'] ?? null) ? trim((string) $data['description']) : null,
            'visibility' => 'private',
            'rules' => $data['rules'] ?? null,
            'cover_style' => $data['cover_style'] ?? 'gold',
        ]);
    }

    public function update(User $user, MediaList $list, array $data): MediaList
    {
        $this->assertOwned($user, $list);
        $list->fill([
            ...(array_key_exists('rules', $data) ? ['rules' => $data['rules']] : []),
            ...(array_key_exists('cover_style', $data) ? ['cover_style' => $data['cover_style']] : []),
            ...(array_key_exists('name', $data) ? ['name' => trim((string) $data['name'])] : []),
            ...(array_key_exists('description', $data) ? ['description' => filled($data['description']) ? trim((string) $data['description']) : null] : []),
        ])->save();

        return $list->refresh();
    }

    public function delete(User $user, MediaList $list): void
    {
        $this->assertOwned($user, $list);
        $list->delete();
    }

    public function addItem(User $user, MediaList $list, string $type, int $id): MediaListItem
    {
        $this->assertOwned($user, $list);
        abort_if($list->rules !== null, 422, 'Smart collections update automatically. Change the rules to change their contents.');
        $this->ownedMedia($user, $type, $id);
        $position = (int) MediaListItem::forUser($user)->where('media_list_id', $list->id)->max('position') + 1;

        return MediaListItem::firstOrCreate([
            'user_id' => $user->id,
            'media_list_id' => $list->id,
            'media_type' => $type,
            'media_id' => $id,
        ], ['position' => $position]);
    }

    public function removeItem(User $user, MediaList $list, MediaListItem $item): void
    {
        $this->assertOwned($user, $list);
        if ($item->user_id !== $user->id || $item->media_list_id !== $list->id) {
            throw new ModelNotFoundException;
        }
        $item->delete();
    }

    /** @param list<int> $itemIds */
    public function reorder(User $user, MediaList $list, array $itemIds): void
    {
        $this->assertOwned($user, $list);
        $ownedIds = MediaListItem::forUser($user)->where('media_list_id', $list->id)->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $ordered = array_values(array_unique(array_map('intval', $itemIds)));
        if (count($ordered) !== count($ownedIds) || array_diff($ordered, $ownedIds) !== [] || array_diff($ownedIds, $ordered) !== []) {
            throw new ModelNotFoundException;
        }

        DB::transaction(function () use ($ordered, $user, $list): void {
            foreach ($ordered as $position => $id) {
                MediaListItem::forUser($user)->where('media_list_id', $list->id)->whereKey($id)->update(['position' => $position + 1]);
            }
        });
    }

    /** @return array<string, mixed> */
    public function summary(User $user, MediaList $list): array
    {
        $this->assertOwned($user, $list);
        $list->loadMissing(['items' => fn ($query) => $query->forUser($user)]);

        return [
            'id' => $list->id,
            'name' => $list->name,
            'description' => $list->description,
            'visibility' => $list->visibility,
            'rules' => $list->rules,
            'coverStyle' => $list->cover_style,
            'shared' => $list->share_token_hash !== null,
            'itemsCount' => $list->items->count(),
            'items' => $list->rules === null ? $list->items->map(fn (MediaListItem $item): array => $this->itemSummary($user, $item))->all() : [],
            'updatedAt' => $list->updated_at?->toIso8601String(),
            ...($list->rules !== null ? app(SmartCollectionService::class)->items($user, $list) : []),
        ];
    }

    private function itemSummary(User $user, MediaListItem $item): array
    {
        $media = $this->ownedMedia($user, $item->media_type, $item->media_id);

        return [
            'id' => $item->id,
            'mediaType' => $item->media_type,
            'mediaId' => $item->media_id,
            'position' => $item->position,
            'title' => $media->title,
            'poster' => $this->metadata->imageUrl($media->poster_path ?? null) ?: ($media->poster_url ?? ''),
            'year' => ($media->release_date ?? $media->first_air_date)?->format('Y'),
        ];
    }

    private function ownedMedia(User $user, string $type, int $id): Movie|Show
    {
        return match ($type) {
            'movie' => Movie::forUser($user)->findOrFail($id),
            'show' => Show::forUser($user)->findOrFail($id),
            default => throw new ModelNotFoundException,
        };
    }

    private function assertOwned(User $user, MediaList $list): void
    {
        if ($list->user_id !== $user->id) {
            throw new ModelNotFoundException;
        }
    }
}
