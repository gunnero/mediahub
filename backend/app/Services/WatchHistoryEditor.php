<?php

namespace App\Services;

use App\Models\Episode;
use App\Models\EpisodeWatch;
use App\Models\MediaEvent;
use App\Models\Movie;
use App\Models\MovieWatch;
use App\Models\User;
use App\Models\WatchRevision;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class WatchHistoryEditor
{
    public function query(User $user, string $type)
    {
        abort_unless(in_array($type, ['movie', 'episode'], true), 404);

        return ($type === 'movie' ? MovieWatch::query() : EpisodeWatch::query())
            ->forUser($user)->whereHas($type, fn ($q) => $q->forUser($user));
    }

    public function change(User $user, string $type, int $id, ?array $data): WatchRevision
    {
        return DB::transaction(function () use ($user, $type, $id, $data) {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $watch = $this->query($user, $type)->lockForUpdate()->findOrFail($id);
            $before = $watch->getAttributes();
            if ($data === null) {
                $watch->delete();
                $after = null;
            } else {
                $data['watched_at'] = CarbonImmutable::parse($data['watched_at'])->utc();
                $watch->fill($data)->save();
                $after = $watch->fresh()->getAttributes();
            }
            $revision = WatchRevision::create(['user_id' => $user->id, 'media_type' => $type,
                'watch_id' => $id, 'before' => $before, 'after' => $after]);
            $this->syncDiary($user, $type, $before, $after);
            if ($type === 'episode' && ($show = $watch->show)) {
                app(CanonicalWatchHistoryService::class)->recalculateShow($user, $show);
            }

            return $revision;
        });
    }

    public function undo(User $user, int $id): void
    {
        DB::transaction(function () use ($user, $id): void {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $revision = WatchRevision::where('user_id', $user->id)->lockForUpdate()->findOrFail($id);
            abort_if($revision->undone_at || $revision->created_at->lt(now()->subMinutes(30)), 409, 'This undo has expired or was already used.');
            abort_if(WatchRevision::where('user_id', $user->id)->where('media_type', $revision->media_type)
                ->where('watch_id', $revision->watch_id)->where('id', '>', $id)->exists(), 409, 'This watch has changed again. Refresh its history.');
            $type = $revision->media_type;
            $current = $this->query($user, $type)->find($revision->watch_id);
            abort_unless(($current?->getAttributes()) == $revision->after, 409, 'This watch has changed. Refresh its history.');
            $before = $revision->before;
            $media = ($type === 'movie' ? Movie::query() : Episode::query())->forUser($user)->findOrFail($before[$type.'_id']);
            if ($current) {
                $current->forceFill($before)->save();
            } else {
                DB::table($type === 'movie' ? 'movie_watches' : 'episode_watches')->insert($before);
            }
            $this->syncDiary($user, $type, $revision->after ?? $before, $before);
            if ($type === 'episode') {
                app(CanonicalWatchHistoryService::class)->recalculateShow($user, $media->show);
            }
            $revision->update(['undone_at' => now()]);
        });
    }

    private function syncDiary(User $user, string $type, array $before, ?array $after): void
    {
        $subject = $type === 'movie' ? Movie::class : Episode::class;
        $others = $this->query($user, $type)->where($type.'_id', $before[$type.'_id'])->where('watched_at', $before['watched_at'])->where('id', '!=', $before['id'])->exists();
        MediaEvent::forUser($user)->where('subject_type', $subject)->where('subject_id', $before[$type.'_id'])
            ->where('event_type', $type.'.watched')->where(function ($query) use ($before, $type, $others) {
                $query->where('metadata->watch_id', $before['id'])
                    ->orWhere('metadata->backfill_key', 'like', 'history:'.$type.'-watch:'.$before['id'].($type === 'movie' ? ':%' : ''));
                if (! $others) {
                    $query->orWhere(fn ($q) => $q->whereNull('metadata->watch_id')->whereNull('metadata->backfill_key')->where('occurred_at', $before['watched_at']));
                }
            })->get()->each(function ($event) use ($after, $before, $type): void {
                $metadata = $event->metadata;
                $metadata['watch_id'] = $before['id'];
                $metadata['media_type'] = $type;
                $metadata['timeline'] = $after !== null;
                $metadata['watch_removed'] = $after === null;
                if ($after) {
                    $metadata['watched_at'] = $after['watched_at'];
                    $event->occurred_at = $after['watched_at'];
                }
                $event->metadata = $metadata;
                $event->save();
            });
    }
}
