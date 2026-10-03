<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\MediaList;
use App\Models\MediaPreference;
use App\Models\SavedLibraryView;
use App\Services\MediaListService;
use App\Services\PersonalLibraryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PersonalLibraryController extends Controller
{
    public function queue(Request $request, PersonalLibraryService $library)
    {
        $data = $request->validate(['minutes' => 'nullable|integer|min:0|max:1440']);

        return response()->json(['items' => $library->queue($request->user(), (int) ($data['minutes'] ?? 0))]);
    }

    public function preferences(Request $request, string $type, int $media, PersonalLibraryService $library)
    {
        $library->owned($request->user(), $type, $media);
        $key = ['user_id' => $request->user()->id, 'media_type' => $type, 'media_id' => $media];
        if ($request->isMethod('patch')) {
            $data = $request->validate(['pinned' => 'sometimes|boolean', 'status' => 'sometimes|in:active,paused,dropped', 'tags' => 'sometimes|array|max:20', 'tags.*' => 'string|min:1|max:40|distinct']);

            return response()->json(MediaPreference::updateOrCreate($key, $data));
        }

        return response()->json(MediaPreference::where($key)->first() ?? ['pinned' => false, 'status' => 'active', 'tags' => []]);
    }

    public function bulk(Request $request, PersonalLibraryService $library, MediaListService $lists)
    {
        $data = $request->validate(['type' => 'required|in:movie,show', 'ids' => 'required|array|min:1|max:100', 'ids.*' => 'required|integer|distinct|min:1',
            'action' => 'required|in:watchlist,remove_watchlist,tag,untag,pin,unpin,active,paused,dropped,list',
            'tag' => 'required_if:action,tag,untag|string|min:1|max:40', 'list_id' => 'required_if:action,list|integer|min:1']);
        DB::transaction(function () use ($request, $data, $library, $lists): void {
            $user = $request->user();
            $items = collect($data['ids'])->map(fn ($id) => $library->owned($user, $data['type'], $id));
            foreach ($items as $item) {
                $action = $data['action'];
                if (in_array($action, ['watchlist', 'remove_watchlist'])) {
                    $item->update([$data['type'] === 'movie' ? 'is_to_watch' : 'followed' => $action === 'watchlist']);
                } elseif ($action === 'list') {
                    $lists->addItem($user, MediaList::forUser($user)->findOrFail($data['list_id']), $data['type'], $item->id);
                } else {
                    $preference = MediaPreference::firstOrCreate(['user_id' => $user->id, 'media_type' => $data['type'], 'media_id' => $item->id]);
                    if (in_array($action, ['tag', 'untag'])) {
                        $tags = collect($preference->tags ?? []);
                        $preference->tags = ($action === 'tag' ? $tags->push(trim($data['tag']))->unique() : $tags->reject(fn ($tag) => $tag === $data['tag']))->values()->all();
                        abort_if(count($preference->tags) > 20, 422, 'A title can have up to 20 tags. Remove a tag before adding another.');
                    } elseif (in_array($action, ['pin', 'unpin'])) {
                        $preference->pinned = $action === 'pin';
                    } else {
                        $preference->status = $action;
                    }
                    $preference->save();
                }
            }
        });

        return response()->json(['updated' => count($data['ids'])]);
    }

    public function views(Request $request)
    {
        if ($request->isMethod('post')) {
            $data = $request->validate(['name' => 'required|string|min:1|max:80', 'media_type' => 'required|in:movie,show',
                'filters' => 'required|array:search,status,sort,tag,personal_status,genre,max_runtime,min_rating,year,unwatched,max_remaining', 'filters.*' => 'nullable|string|max:120']);

            return response()->json(SavedLibraryView::create(['user_id' => $request->user()->id, ...$data]), 201);
        }

        return response()->json(['views' => SavedLibraryView::where('user_id', $request->user()->id)->orderBy('name')->get()]);
    }

    public function deleteView(Request $request, int $view)
    {
        SavedLibraryView::where('user_id', $request->user()->id)->findOrFail($view)->delete();

        return response()->noContent();
    }
}
