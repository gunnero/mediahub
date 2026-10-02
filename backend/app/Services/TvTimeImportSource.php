<?php

namespace App\Services;

use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use PDO;

class TvTimeImportSource
{
    /** Read and validate the entire snapshot before the destination is changed. */
    public function read(string $path, string $extension): array
    {
        if ($extension === 'json') {
            $payload = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($payload) || array_is_list($payload) || isset($payload['schema'])) {
                throw new InvalidArgumentException('Unsupported dashboard snapshot.');
            }
            foreach (['followedNewEpisodes', 'moviesToCheckOut', 'alerts'] as $key) {
                if (! isset($payload[$key]) || ! is_array($payload[$key]) || ! array_is_list($payload[$key])) {
                    throw new InvalidArgumentException('Incomplete dashboard snapshot.');
                }
            }
            $source = [
                'format' => 'json',
                'shows' => $payload['followedNewEpisodes'],
                'movies' => $payload['moviesToCheckOut'],
                'episode_watches' => [],
                'alerts' => $payload['alerts'],
            ];
            foreach (['shows', 'movies'] as $key) {
                $this->validateRows($source[$key], [
                    'id' => ['required', 'distinct'],
                    'title' => ['required', 'string', 'max:255'],
                    'poster' => ['nullable', 'string'],
                    'backdrop' => ['nullable', 'string'],
                ]);
            }
        } else {
            $database = new PDO('sqlite:'.$path, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
            $database->exec('PRAGMA query_only = ON');
            $database->beginTransaction();
            $source = ['format' => 'sqlite'];
            foreach (['shows' => 'title', 'movies' => 'title', 'episode_watches' => 'id', 'alerts' => 'id'] as $table => $order) {
                $source[$table] = $database->query('SELECT * FROM '.$table.' ORDER BY '.$order)->fetchAll();
            }
            $database->commit();
            $this->validateRows($source['shows'], [
                'show_key' => ['required', 'distinct'],
                'title' => ['required', 'string', 'max:255'],
                'poster_url' => ['nullable', 'string'],
                'fanart_url' => ['nullable', 'string'],
                'followed' => ['sometimes', 'boolean'],
                'seen_episodes' => ['sometimes', 'integer', 'min:0'],
                'aired_episodes' => ['sometimes', 'integer', 'min:0'],
                'runtime' => ['sometimes', 'integer', 'min:0'],
                'latest_seen_at' => ['nullable', 'date'],
            ]);
            $this->validateRows($source['movies'], [
                'uuid' => ['required', 'distinct'],
                'title' => ['required', 'string', 'max:255'],
                'runtime' => ['sometimes', 'integer', 'min:0'],
                'is_to_watch' => ['sometimes', 'boolean'],
                'watch_count' => ['sometimes', 'integer', 'min:1'],
                'watched_at' => ['nullable', 'date'],
            ]);
            $this->validateRows($source['episode_watches'], [
                'id' => ['required', 'integer', 'min:1', 'distinct'],
                'episode_id' => ['nullable'],
                'show_key' => ['nullable'],
                'show_title' => ['required', 'string', 'max:255'],
                'season_number' => ['nullable', 'integer', 'min:0'],
                'episode_number' => ['nullable', 'integer', 'min:0'],
                'runtime' => ['sometimes', 'integer', 'min:0'],
                'watched_at' => ['nullable', 'date'],
            ]);
        }

        $this->validateRows($source['alerts'], [
            'title' => ['required', 'string', 'max:255'],
            'category' => ['sometimes', 'string', 'max:255'],
            'subtitle' => ['sometimes', 'string', 'max:255'],
            'due_text' => ['sometimes', 'string', 'max:255'],
            'dueText' => ['sometimes', 'string', 'max:255'],
            'unread' => ['sometimes', 'boolean'],
        ]);
        if (count($source['shows']) + count($source['movies']) + count($source['episode_watches']) === 0) {
            throw new InvalidArgumentException('Snapshot has no media records.');
        }

        return $source;
    }

    private function validateRows(array $rows, array $fields): void
    {
        $rules = [];
        $uniqueFields = [];
        foreach ($fields as $field => $fieldRules) {
            $rules[$field] = array_values(array_diff($fieldRules, ['distinct']));
            if (in_array('distinct', $fieldRules, true)) {
                $uniqueFields[$field] = [];
            }
        }
        foreach ($rows as $row) {
            if (! is_array($row)) {
                throw new InvalidArgumentException('Invalid snapshot row.');
            }
            foreach (array_keys($fields) as $field) {
                if (isset($row[$field]) && ! is_scalar($row[$field])) {
                    throw new InvalidArgumentException('Invalid snapshot field.');
                }
                if (isset($row[$field]) && in_array($field, ['id', 'uuid', 'show_key', 'episode_id'], true)
                    && ((! is_string($row[$field]) && ! is_int($row[$field])) || strlen((string) $row[$field]) > 255)) {
                    throw new InvalidArgumentException('Invalid snapshot identifier.');
                }
            }
            Validator::make($row, $rules)->validate();
            // Avoid expanding thousands of wildcard rules and quadratic distinct checks.
            foreach ($uniqueFields as $field => $_) {
                $key = (string) $row[$field];
                if (isset($uniqueFields[$field][$key])) {
                    throw new InvalidArgumentException('Duplicate snapshot identifier.');
                }
                $uniqueFields[$field][$key] = true;
            }
        }
    }
}
