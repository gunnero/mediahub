<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\MediaList;
use App\Models\MediaListItem;
use App\Services\MediaListService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class MediaListController extends Controller
{
    public function index(Request $request, MediaListService $lists): JsonResponse
    {
        return response()->json(['lists' => $lists->all($request->user())]);
    }

    public function store(Request $request, MediaListService $lists): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:120'], 'description' => ['nullable', 'string', 'max:1000'], ...$this->collectionRules()]);
        $list = $lists->create($request->user(), $data);

        return response()->json(['list' => $lists->summary($request->user(), $list)], 201);
    }

    public function show(Request $request, MediaList $list, MediaListService $lists): JsonResponse
    {
        return response()->json(['list' => $lists->summary($request->user(), $list)]);
    }

    public function update(Request $request, MediaList $list, MediaListService $lists): JsonResponse
    {
        $data = $request->validate(['name' => ['sometimes', 'required', 'string', 'max:120'], 'description' => ['sometimes', 'nullable', 'string', 'max:1000'], ...$this->collectionRules()]);
        $list = $lists->update($request->user(), $list, $data);

        return response()->json(['list' => $lists->summary($request->user(), $list)]);
    }

    public function destroy(Request $request, MediaList $list, MediaListService $lists): JsonResponse
    {
        $lists->delete($request->user(), $list);

        return response()->json(null, 204);
    }

    public function addItem(Request $request, MediaList $list, MediaListService $lists): JsonResponse
    {
        $data = $request->validate(['media_type' => ['required', Rule::in(['movie', 'show'])], 'media_id' => ['required', 'integer', 'min:1']]);
        $item = $lists->addItem($request->user(), $list, $data['media_type'], (int) $data['media_id']);

        return response()->json(['item' => ['id' => $item->id], 'list' => $lists->summary($request->user(), $list->refresh())], 201);
    }

    public function removeItem(Request $request, MediaList $list, MediaListItem $item, MediaListService $lists): JsonResponse
    {
        $lists->removeItem($request->user(), $list, $item);

        return response()->json(null, 204);
    }

    public function reorder(Request $request, MediaList $list, MediaListService $lists): JsonResponse
    {
        $data = $request->validate(['item_ids' => ['required', 'array'], 'item_ids.*' => ['integer', 'distinct', 'min:1']]);
        $lists->reorder($request->user(), $list, $data['item_ids']);

        return response()->json(['list' => $lists->summary($request->user(), $list->refresh())]);
    }

    private function collectionRules(): array
    {
        return ['cover_style' => 'sometimes|in:gold,blue,green,rose', 'rules' => 'sometimes|nullable|array:type,genre,max_runtime,min_rating,year,unwatched,max_remaining,tag,personal_status',
            'rules.type' => 'required_with:rules|in:movie,show', 'rules.genre' => 'nullable|string|max:40', 'rules.tag' => 'nullable|string|max:40',
            'rules.max_runtime' => 'nullable|integer|min:1|max:1440', 'rules.min_rating' => 'nullable|numeric|min:0|max:10',
            'rules.year' => 'nullable|integer|min:1888|max:2100', 'rules.unwatched' => 'sometimes|boolean',
            'rules.max_remaining' => 'nullable|integer|min:1|max:1000', 'rules.personal_status' => 'nullable|in:active,paused,dropped'];
    }

    public function share(Request $request, MediaList $list)
    {
        abort_unless($list->user_id === $request->user()->id, 404);
        if ($request->isMethod('delete')) {
            $list->update(['share_token_hash' => null]);

            return response()->noContent();
        }
        $token = Str::random(48);
        $list->update(['share_token_hash' => hash('sha256', $token)]);

        return response()->json(['url' => url('/collections/'.$token)]);
    }

    public function publicCollection(string $token, MediaListService $lists)
    {
        $list = MediaList::where('share_token_hash', hash('sha256', $token))->firstOrFail();
        $summary = $lists->summary($list->user, $list);

        // Explicitly shared title metadata only; no owner IDs, notes, ratings or rules.
        return response()->json(['name' => $list->name, 'description' => $list->description, 'coverStyle' => $list->cover_style,
            'itemsCount' => $summary['itemsCount'], 'truncated' => $summary['truncated'] ?? false, 'items' => collect($summary['items'])->map(fn ($item) => ['title' => $item['title'], 'year' => $item['year'], 'poster' => str_starts_with($item['poster'] ?? '', 'https://image.tmdb.org/t/p/') ? $item['poster'] : ''])]);
    }
}
