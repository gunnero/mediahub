<?php

namespace App\Services;

use App\Models\Episode;
use App\Models\EpisodeWatch;
use App\Models\ImportBatch;
use App\Models\MediaList;
use App\Models\MediaListItem;
use App\Models\MediaPreference;
use App\Models\Movie;
use App\Models\MovieWatch;
use App\Models\Note;
use App\Models\Rating;
use App\Models\Show;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class LibraryImportService
{
    private const TABLES = ['movies', 'shows', 'episodes', 'movie_watches', 'episode_watches', 'ratings', 'notes', 'media_lists', 'media_list_items', 'media_preferences', 'media_links', 'playback_progress', 'playback_sessions', 'release_reminders'];

    public function snapshot(User $user): array
    {
        $snapshot = [];
        foreach (self::TABLES as $table) {
            $snapshot[$table] = DB::table($table)->where('user_id', $user->id)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }
        $snapshot['_favorites'] = $user->fresh()->only(['favorite_movie_ids', 'favorite_show_ids', 'featured_list_ids']);

        return $snapshot;
    }

    public function fingerprint(array $data): string
    {
        return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
    }

    public function read(string $path, string $extension): array
    {
        $raw = $extension === 'json' ? json_decode(file_get_contents($path), true, 64, JSON_THROW_ON_ERROR) : null;
        if (is_array($raw) && ($raw['schema'] ?? '') === 'mediahub-user-export-v1') {
            $data = Arr::only($raw, ['movies', 'shows', 'episodes', 'movie_watches', 'episode_watches', 'ratings', 'notes', 'lists', 'library_preferences']);
        } else {
            $source = app(TvTimeImportSource::class)->read($path, $extension);
            $data = array_fill_keys(['movies', 'shows', 'episodes', 'movie_watches', 'episode_watches', 'ratings', 'notes', 'lists'], []);
            foreach ($source['shows'] as $index => $row) {
                $data['shows'][] = ['id' => $index + 1, 'title' => $row['title'], 'external_id' => (string) ($row['show_key'] ?? $row['id']), 'followed' => (bool) ($row['followed'] ?? true)];
            }
            foreach ($source['movies'] as $index => $row) {
                $data['movies'][] = ['id' => $index + 1, 'title' => $row['title'], 'external_id' => (string) ($row['uuid'] ?? $row['id']), 'runtime' => (int) ($row['runtime'] ?? 0), 'watchlist' => (bool) ($row['is_to_watch'] ?? true)];
                if (! empty($row['watched_at'])) {
                    $data['movie_watches'][] = ['movie_id' => $index + 1, 'watched_at' => $row['watched_at'], 'runtime' => (int) ($row['runtime'] ?? 0), 'watch_count' => (int) ($row['watch_count'] ?? 1)];
                }
            }
            $episodes = [];
            foreach ($source['episode_watches'] as $row) {
                $showIndex = collect($data['shows'])->search(fn ($show) => ! empty($row['show_key']) ? $show['external_id'] === (string) $row['show_key'] : $show['title'] === $row['show_title']);
                if ($showIndex === false) {
                    $showIndex = count($data['shows']);
                    $data['shows'][] = ['id' => $showIndex + 1, 'title' => $row['show_title'], 'external_id' => (string) ($row['show_key'] ?? 'title:'.$row['show_title']), 'followed' => false];
                }
                if (! isset($row['season_number'], $row['episode_number'])) {
                    throw ValidationException::withMessages(['file' => 'Some episode watches have no season or episode number. Repair the source before importing.']);
                }
                $key = ($showIndex + 1).':'.$row['season_number'].':'.$row['episode_number'];
                if (! isset($episodes[$key])) {
                    $episodes[$key] = count($data['episodes']) + 1;
                    $data['episodes'][] = ['id' => $episodes[$key], 'show_id' => $showIndex + 1, 'season_number' => (int) $row['season_number'], 'episode_number' => (int) $row['episode_number'], 'title' => $row['title'] ?? 'Episode '.$row['episode_number'], 'runtime' => (int) ($row['runtime'] ?? 0)];
                }
                if (! empty($row['watched_at'])) {
                    $data['episode_watches'][] = ['episode_id' => $episodes[$key], 'show_id' => $showIndex + 1, 'watched_at' => $row['watched_at'], 'runtime' => (int) ($row['runtime'] ?? 0)];
                }
            }
        }
        $this->validate($data);

        return $data;
    }

    private function validate(array $data): void
    {
        $rules = [];
        foreach (['movies', 'shows', 'episodes', 'movie_watches', 'episode_watches', 'ratings', 'notes', 'lists'] as $key) {
            $rules[$key] = 'required|array|max:20000';
        }
        // Empty arrays are valid for datasets not present in an archive.
        foreach ($rules as $key => $rule) {
            $rules[$key] = str_replace('required', 'present', $rule);
        }
        foreach (['movies', 'shows', 'episodes'] as $key) {
            $rules[$key.'.*.id'] = 'required|integer|min:1|distinct';
            $rules[$key.'.*.title'] = 'required|string|max:255';
            $rules[$key.'.*.runtime'] = 'nullable|integer|min:0|max:1440';
            $rules[$key.'.*.tmdb_id'] = 'nullable|integer|min:1';
            $rules[$key.'.*.imdb_id'] = 'nullable|regex:/^tt[0-9]+$/';
            $rules[$key.'.*.external_id'] = 'nullable|string|max:255';
        }
        $rules += ['movies.*.release_date' => 'nullable|date', 'shows.*.first_air_date' => 'nullable|date', 'episodes.*.air_date' => 'nullable|date',
            'movies.*.watchlist' => 'sometimes|boolean', 'shows.*.followed' => 'sometimes|boolean',
            'episodes.*.show_id' => 'required|integer|min:1', 'episodes.*.season_number' => 'required|integer|min:0|max:1000', 'episodes.*.episode_number' => 'required|integer|min:0|max:10000'];
        foreach (['movie', 'episode'] as $type) {
            $rules[$type.'_watches.*.'.$type.'_id'] = 'required|integer|min:1';
            $rules[$type.'_watches.*.watched_at'] = 'nullable|date|before_or_equal:now';
            $rules[$type.'_watches.*.runtime'] = 'nullable|integer|min:0|max:1440';
        }
        $rules += ['movie_watches.*.watch_count' => 'nullable|integer|min:1|max:10000', 'ratings.*.media_type' => 'required|in:movie,show,episode', 'ratings.*.media_id' => 'required|integer|min:1', 'ratings.*.rating' => 'required|integer|min:1|max:10',
            'notes.*.media_type' => 'required|in:movie,show,episode', 'notes.*.media_id' => 'required|integer|min:1', 'notes.*.body' => 'required|string|max:5000',
            'lists.*.name' => 'required|string|min:1|max:120', 'lists.*.description' => 'nullable|string|max:1000', 'lists.*.items' => 'present|array|max:20000',
            'lists.*.items.*.media_type' => 'required|in:movie,show', 'lists.*.items.*.media_id' => 'required|integer|min:1'];
        $rules += ['library_preferences' => 'sometimes|array|max:20000', 'library_preferences.*.media_type' => 'required|in:movie,show', 'library_preferences.*.media_id' => 'required|integer|min:1',
            'library_preferences.*.pinned' => 'sometimes|boolean', 'library_preferences.*.status' => 'sometimes|in:active,paused,dropped', 'library_preferences.*.tags' => 'nullable|array|max:20', 'library_preferences.*.tags.*' => 'string|min:1|max:40',
            'lists.*.cover_style' => 'sometimes|in:gold,blue,green,rose', 'lists.*.rules' => 'sometimes|nullable|array:type,genre,max_runtime,min_rating,year,unwatched,max_remaining,tag,personal_status',
            'lists.*.rules.type' => 'required_with:lists.*.rules|in:movie,show', 'lists.*.rules.genre' => 'nullable|string|max:40', 'lists.*.rules.tag' => 'nullable|string|max:40',
            'lists.*.rules.max_runtime' => 'nullable|integer|min:1|max:1440', 'lists.*.rules.min_rating' => 'nullable|numeric|min:0|max:10', 'lists.*.rules.year' => 'nullable|integer|min:1888|max:2100',
            'lists.*.rules.unwatched' => 'sometimes|boolean', 'lists.*.rules.max_remaining' => 'nullable|integer|min:1|max:1000', 'lists.*.rules.personal_status' => 'nullable|in:active,paused,dropped'];
        Validator::make($data, $rules)->validate();
        $ids = ['movie' => array_column($data['movies'], 'id'), 'show' => array_column($data['shows'], 'id'), 'episode' => array_column($data['episodes'], 'id')];
        $check = function ($type, $id) use ($ids): void {
            if (! in_array($id, $ids[$type], true)) {
                throw ValidationException::withMessages(['file' => 'The archive has a missing media reference. No changes were made.']);
            }
        };
        foreach ($data['episodes'] as $row) {
            $check('show', $row['show_id']);
        }
        foreach (['movie', 'episode'] as $type) {
            foreach ($data[$type.'_watches'] as $row) {
                $check($type, $row[$type.'_id']);
            }
        }
        foreach ([...$data['ratings'], ...$data['notes'], ...($data['library_preferences'] ?? [])] as $row) {
            $check($row['media_type'], $row['media_id']);
        }
        foreach ($data['lists'] as $list) {
            foreach ($list['items'] as $row) {
                $check($row['media_type'], $row['media_id']);
            }
        }
    }

    private function matchMedia(User $user, string $type, array $row)
    {
        $model = $type === 'movie' ? Movie::class : Show::class;
        $base = $model::forUser($user);
        if (! empty($row['tmdb_id'])) {
            $base->where('tmdb_id', $row['tmdb_id']);
        } elseif (! empty($row['imdb_id'])) {
            $base->where('imdb_id', $row['imdb_id']);
        } elseif (! empty($row['external_id'])) {
            $base->where('external_source', 'tvtime')->where('external_id', $row['external_id']);
        } else {
            $base->where('title', $row['title']);
            $date = $type === 'movie' ? 'release_date' : 'first_air_date';
            if (! empty($row[$date])) {
                $base->whereDate($date, $row[$date]);
            }
        }
        $matches = $base->limit(2)->get();
        if ($matches->count() > 1) {
            throw ValidationException::withMessages(['file' => 'Multiple library titles match '.$row['title'].'. Resolve this duplicate before importing.']);
        }

        return $matches->first();
    }

    public function preview(User $user, array $data): ImportBatch
    {
        $summary = ['movies' => count($data['movies']), 'shows' => count($data['shows']), 'watches' => count($data['movie_watches']) + count($data['episode_watches']), 'existingTitles' => 0, 'duplicateWatches' => 0];
        $maps = [];
        foreach (['movie' => 'movies', 'show' => 'shows'] as $type => $key) {
            foreach ($data[$key] as $row) {
                if ($media = $this->matchMedia($user, $type, $row)) {
                    $summary['existingTitles']++;
                    $maps[$type][$row['id']] = $media->id;
                }
            }
        }
        foreach ($data['episodes'] as $row) {
            if (isset($maps['show'][$row['show_id']])) {
                $maps['episode'][$row['id']] = Episode::forUser($user)->where('show_id', $maps['show'][$row['show_id']])->where('season_number', $row['season_number'])->where('episode_number', $row['episode_number'])->value('id');
            }
        }
        foreach (['movie' => MovieWatch::class, 'episode' => EpisodeWatch::class] as $type => $model) {
            $occurrences = [];
            foreach ($data[$type.'_watches'] as $row) {
                $id = $maps[$type][$row[$type.'_id']] ?? null;
                if (! $id || empty($row['watched_at'])) {
                    continue;
                }
                $key = ['user_id' => $user->id, $type.'_id' => $id, 'watched_at' => CarbonImmutable::parse($row['watched_at'])->utc()->format('Y-m-d H:i:s')];
                if ($type === 'movie') {
                    $key['watch_count'] = $row['watch_count'] ?? 1;
                }
                $signature = $this->fingerprint($key);
                $occurrences[$signature] = ($occurrences[$signature] ?? 0) + 1;
                if ($model::where($key)->count() >= $occurrences[$signature]) {
                    $summary['duplicateWatches']++;
                }
            }
        }

        return ImportBatch::create(['user_id' => $user->id, 'status' => 'preview', 'payload' => $data, 'summary' => $summary, 'before_hash' => $this->fingerprint($this->snapshot($user))]);
    }

    public function apply(User $user, ImportBatch $batch): ImportBatch
    {
        return DB::transaction(function () use ($user, $batch) {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $batch = ImportBatch::where('user_id', $user->id)->lockForUpdate()->findOrFail($batch->id);
            if ($batch->status === 'completed') {
                return $batch;
            }
            abort_unless($batch->status === 'preview', 409, 'This import cannot be applied.');
            $backup = $this->snapshot($user);
            abort_unless(hash_equals($batch->before_hash, $this->fingerprint($backup)), 409, 'Your library changed after preview. Upload again to review the current merge.');
            $batch->update(['backup' => $backup, 'backup_hash' => $this->fingerprint($backup)]);
            abort_unless(hash_equals($batch->backup_hash, $this->fingerprint($batch->fresh()->backup)), 500, 'Recovery snapshot verification failed.');
            $data = $batch->payload;
            $created = [];
            $maps = [];
            $remember = function ($model) use (&$created) {
                $created[$model->getTable()][] = $model->id;

                return $model;
            };
            foreach (['movie' => 'movies', 'show' => 'shows'] as $type => $key) {
                foreach ($data[$key] as $row) {
                    $model = $type === 'movie' ? Movie::class : Show::class;
                    $media = $this->matchMedia($user, $type, $row);
                    if (! $media) {
                        $attrs = Arr::only($row, ['title', 'tmdb_id', 'imdb_id', 'runtime', 'release_date', 'first_air_date', 'followed']);
                        if (isset($row['external_id'])) {
                            $attrs += ['external_source' => 'tvtime', 'external_id' => $row['external_id']];
                        }
                        if ($type === 'movie') {
                            $attrs['is_to_watch'] = $row['watchlist'] ?? false;
                        }
                        $media = $remember($model::create(['user_id' => $user->id, ...$attrs]));
                    }
                    $maps[$type][$row['id']] = $media->id;
                }
            }
            foreach ($data['episodes'] as $row) {
                $key = ['user_id' => $user->id, 'show_id' => $maps['show'][$row['show_id']], 'season_number' => $row['season_number'], 'episode_number' => $row['episode_number']];
                $episode = Episode::where($key)->first();
                if (! $episode) {
                    $episode = $remember(Episode::create([...$key, ...Arr::only($row, ['title', 'tmdb_id', 'air_date', 'runtime'])]));
                }
                $maps['episode'][$row['id']] = $episode->id;
            }
            $duplicates = 0;
            foreach (['movie', 'episode'] as $type) {
                $occurrences = [];
                foreach ($data[$type.'_watches'] as $row) {
                    if (empty($row['watched_at'])) {
                        continue;
                    }
                    $model = $type === 'movie' ? MovieWatch::class : EpisodeWatch::class;
                    $key = ['user_id' => $user->id, $type.'_id' => $maps[$type][$row[$type.'_id']], 'watched_at' => CarbonImmutable::parse($row['watched_at'])->utc()->format('Y-m-d H:i:s')];
                    if ($type === 'movie') {
                        $key['watch_count'] = $row['watch_count'] ?? 1;
                    }
                    $signature = $this->fingerprint($key);
                    $occurrences[$signature] = ($occurrences[$signature] ?? 0) + 1;
                    if ($model::where($key)->count() >= $occurrences[$signature]) {
                        $duplicates++;

                        continue;
                    }
                    if ($type === 'episode') {
                        $key['show_id'] = Episode::forUser($user)->findOrFail($key['episode_id'])->show_id;
                    }
                    $remember($model::create([...$key, 'runtime' => $row['runtime'] ?? 0, 'source' => 'import']));
                }
            }
            foreach (['ratings' => Rating::class, 'notes' => Note::class] as $key => $model) {
                foreach ($data[$key] as $row) {
                    $identity = ['user_id' => $user->id, 'media_type' => $row['media_type'], 'media_id' => $maps[$row['media_type']][$row['media_id']]];
                    if ($key === 'notes') {
                        $identity['body'] = $row['body'];
                    }
                    if (! $model::where($identity)->exists()) {
                        $remember($model::create([...$identity, ...Arr::only($row, ['body', 'rating'])]));
                    }
                }
            }
            foreach ($data['lists'] as $row) {
                $list = MediaList::forUser($user)->where('name', $row['name'])->first();
                if (! $list) {
                    $list = $remember(MediaList::create(['user_id' => $user->id, 'name' => $row['name'], 'description' => $row['description'] ?? null, 'visibility' => 'private', 'rules' => $row['rules'] ?? null, 'cover_style' => $row['cover_style'] ?? 'gold']));
                }
                if ($list->rules) {
                    continue;
                }
                foreach ($row['items'] as $position => $item) {
                    $key = ['user_id' => $user->id, 'media_list_id' => $list->id, 'media_type' => $item['media_type'], 'media_id' => $maps[$item['media_type']][$item['media_id']]];
                    if (! MediaListItem::where($key)->exists()) {
                        $remember(MediaListItem::create([...$key, 'position' => (int) $list->items()->max('position') + 1]));
                    }
                }
            }
            foreach ($data['library_preferences'] ?? [] as $row) {
                $key = ['user_id' => $user->id, 'media_type' => $row['media_type'], 'media_id' => $maps[$row['media_type']][$row['media_id']]];
                if (! MediaPreference::where($key)->exists()) {
                    $remember(MediaPreference::create([...$key, ...Arr::only($row, ['pinned', 'status', 'tags'])]));
                }
            }
            app(CanonicalWatchHistoryService::class)->recalculateUser($user);
            $batch->update(['status' => 'completed', 'created_rows' => $created, 'after_hash' => $this->fingerprint($this->snapshot($user)),
                'summary' => [...$batch->summary, 'created' => array_map('count', $created), 'duplicateWatchesSkipped' => $duplicates]]);

            return $batch;
        });
    }

    public function recover(User $user, ImportBatch $batch): void
    {
        DB::transaction(function () use ($user, $batch): void {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $batch = ImportBatch::where('user_id', $user->id)->lockForUpdate()->findOrFail($batch->id);
            abort_unless($batch->status === 'completed', 409, 'This import cannot be undone.');
            abort_unless(hash_equals($batch->after_hash, $this->fingerprint($this->snapshot($user))), 409, 'Your library changed after import. Recovery stopped to preserve those changes.');
            abort_unless(hash_equals($batch->backup_hash, $this->fingerprint($batch->backup)), 409, 'Recovery snapshot did not pass verification.');
            foreach (array_reverse(self::TABLES) as $table) {
                DB::table($table)->where('user_id', $user->id)->whereIn('id', $batch->created_rows[$table] ?? [])->delete();
            }
            // Recalculation touched existing show counters; restore only those derived fields.
            foreach ($batch->backup['shows'] as $row) {
                DB::table('shows')->where('user_id', $user->id)->where('id', $row['id'])->update(Arr::only($row, ['seen_episodes', 'aired_episodes', 'latest_seen_at', 'updated_at']));
            }
            abort_unless(hash_equals($batch->backup_hash, $this->fingerprint($this->snapshot($user))), 409, 'Recovery verification failed; no changes were saved.');
            $batch->update(['status' => 'recovered']);
        });
    }
}
