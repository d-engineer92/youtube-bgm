<?php

namespace App\Services\YouTube;

use App\Models\Channel;
use App\Models\ExplorationTask;
use App\Models\FetchRun;
use App\Models\Observation;
use App\Models\RunItem;
use App\Models\Target;
use App\Models\Video;
use App\Services\Retention;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Collector
{
    public function __construct(private ApiCaller $api, private Retention $retention) {}

    public function search(array $conditions): FetchRun
    {
        return $this->locked(function () use ($conditions) {
            $run = $this->newRun('search', $conditions);

            return $this->execute($run, $conditions['limit']);
        });
    }

    public function collectTask(ExplorationTask $task): FetchRun
    {
        return $this->locked(function () use ($task) {
            $run = DB::transaction(function () use ($task) {
                $task = ExplorationTask::lockForUpdate()->findOrFail($task->id);
                if ($task->fetch_run_id) {
                    return $task->fetchRun;
                }
                $run = $this->newRun('search', $task->conditions);
                $run->update(['expires_at' => $task->expires_at]);
                $task->update(['fetch_run_id' => $run->id]);

                return $run;
            });

            return $this->execute($run, 10);
        });
    }

    public function register(array $reference, int $limit): FetchRun
    {
        return $this->locked(function () use ($reference, $limit) {
            $run = $this->newRun($reference['kind'] === 'channel' ? 'uploads' : 'direct', ['reference' => $reference, 'limit' => $limit]);
            $this->add($run, $reference['kind'], $reference['id']);

            return $this->execute($run, $limit);
        });
    }

    public function resume(FetchRun $run, int $limit): FetchRun
    {
        return $this->locked(function () use ($run, $limit) {
            $fresh = $run->fresh();
            if (! $fresh) {
                throw new ApiFailure('expired_run');
            }

            return $this->execute($fresh, $limit);
        });
    }

    public function refreshWatch(bool $daily = false): FetchRun
    {
        return $this->locked(function () use ($daily) {
            $key = $daily ? config('youtube.mode').':daily:'.now('Asia/Tokyo')->toDateString() : null;
            $run = $key ? FetchRun::inMode()->where('run_key', $key)->first() : null;
            if ($run && $run->status === 'complete') {
                return $run;
            }
            if (! $run) {
                $targets = Target::inMode()->where('watched', true)->get();
                $run = $this->newRun('watch', ['target_ids' => $targets->pluck('id')->all(), 'limit' => config('youtube.initial_limit'), 'scans' => []], $key);
                foreach ($targets as $target) {
                    $this->add($run, $target->kind, $target->external_id);
                    if ($target->kind === 'channel') {
                        foreach (Video::where('channel_external_id', $target->external_id)->whereHas('target', fn ($q) => $q->inMode())->with('target')->get() as $video) {
                            $this->add($run, 'video', $video->target->external_id);
                        }
                    }
                }
            }

            return $this->execute($run, config('youtube.initial_limit'));
        });
    }

    private function locked(callable $work): FetchRun
    {
        $this->retention->prune();
        // One local user: serialize collection, including manual and scheduled operations.
        $result = Cache::lock('youtube:collection', 180)->get(function () use ($work) {
            $this->api->reset();

            return $work();
        });
        if ($result === false) {
            throw new ApiFailure('busy');
        }

        return $result;
    }

    private function newRun(string $kind, array $conditions, ?string $key = null): FetchRun
    {
        return FetchRun::create(['source' => config('youtube.mode'), 'kind' => $kind, 'conditions' => $conditions, 'run_key' => $key ?? (string) Str::uuid(), 'started_at' => now(), 'expires_at' => now()->addDays(config('youtube.retention_days'))]);
    }

    private function execute(FetchRun $run, int $limit): FetchRun
    {
        if ($run->source !== config('youtube.mode') || $run->expires_at <= now()) {
            throw new ApiFailure('expired_run');
        }
        $run->update(['status' => 'running', 'error_code' => null, 'finished_at' => null]);
        $this->api->setExploration($run->conditions['exploration_id'] ?? null);
        try {
            $this->hydrate($run);
            if ($run->kind === 'search' || $run->kind === 'uploads') {
                if ($run->kind === 'search') {
                    $this->pages($run, 'search', $this->searchParameters($run->conditions), $limit);
                } else {
                    $target = Target::inMode()->where('kind', 'channel')->where('external_id', $run->conditions['reference']['id'])->first();
                    if (! $target?->channel?->uploads_playlist || $target->last_status !== 'success') {
                        throw new ApiFailure('channel_unavailable');
                    }
                    $this->pages($run, 'playlistItems', ['playlistId' => $target->channel->uploads_playlist], $limit);
                }
            } elseif ($run->kind === 'watch') {
                foreach (Target::inMode()->whereIn('id', $run->conditions['target_ids'])->where('kind', 'channel')->get() as $target) {
                    $conditions = $run->conditions;
                    if (isset($conditions['scans'][(string) $target->id])) {
                        continue;
                    }
                    if (! $target->channel?->uploads_playlist || $target->last_status !== 'success') {
                        continue;
                    }
                    $page = $this->api->call('playlistItems', ['playlistId' => $target->channel->uploads_playlist, 'maxResults' => min(50, $limit)]);
                    $this->acceptPage($run, 'playlistItems', $page);
                    $conditions['scans'][(string) $target->id] = ['items' => count($page['items']), 'has_more' => ! empty($page['nextPageToken'])];
                    $run->update(['conditions' => $conditions]);
                    $this->hydrate($run);
                }
                $run->update(['listing_done' => true]);
            } else {
                $run->update(['listing_done' => true]);
            }
        } catch (ApiFailure $e) {
            $run->update(['error_code' => $e->reason]);
        }
        $success = $run->items()->where('status', 'success')->count();
        $unresolved = $run->items()->where('status', '!=', 'success')->count();
        $run->update(['status' => ! $run->error_code && ! $unresolved ? 'complete' : ($success ? 'partial' : 'failed'), 'finished_at' => now()]);
        $this->api->setExploration(null);

        return $run->fresh();
    }

    private function searchParameters(array $c): array
    {
        $params = ['q' => $c['q'], 'type' => 'video', 'order' => $c['order'], 'videoDuration' => $c['duration']];
        // Saved exploration timestamps are already UTC, unlike manual form dates.
        foreach (['regionCode', 'relevanceLanguage', 'publishedAfter', 'publishedBefore'] as $key) {
            if (isset($c[$key])) {
                $params[$key] = $c[$key];
            }
        }
        if (! empty($c['published_after'])) {
            $params['publishedAfter'] = CarbonImmutable::parse($c['published_after'], 'Asia/Tokyo')->utc()->toIso8601ZuluString();
        }
        if (! empty($c['published_before'])) {
            $params['publishedBefore'] = CarbonImmutable::parse($c['published_before'], 'Asia/Tokyo')->endOfDay()->utc()->toIso8601ZuluString();
        }

        return $params;
    }

    private function pages(FetchRun $run, string $method, array $parameters, int $limit): void
    {
        $listed = 0;
        while (! $run->listing_done && $listed < $limit && (! isset($run->conditions['page_limit']) || $run->pages < $run->conditions['page_limit'])) {
            $params = $parameters + ['maxResults' => min(50, $limit - $listed)];
            if ($run->next_page_token !== null) {
                $params['pageToken'] = $run->next_page_token;
            }
            $page = $this->api->call($method, $params);
            if (isset($run->conditions['page_limit']) && (count($page['items'] ?? []) > 10 || (isset($page['nextPageToken']) && (! is_string($page['nextPageToken']) || strlen($page['nextPageToken']) > 4096)))) {
                throw new ApiFailure('invalid_response');
            }
            if (! isset($page['items']) || ! is_array($page['items']) || (! empty($page['nextPageToken']) && $page['nextPageToken'] === $run->next_page_token)) {
                throw new ApiFailure('invalid_response');
            }
            $listed += count($page['items']);
            // Commit the discovered IDs and cursor before hydration so a partial failure can resume.
            DB::transaction(function () use ($run, $method, $page) {
                $this->acceptPage($run, $method, $page);
                $run->update(['pages' => $run->pages + 1, 'next_page_token' => $page['nextPageToken'] ?? null, 'listing_done' => empty($page['nextPageToken']), 'search_hits' => $run->search_hits + count($page['items']), 'response_region' => is_string($page['regionCode'] ?? null) ? $page['regionCode'] : null, 'listed_at' => now()]);
            });
            $this->hydrate($run);
        }
    }

    private function acceptPage(FetchRun $run, string $method, array $page): void
    {
        if (! isset($page['items']) || ! is_array($page['items'])) {
            throw new ApiFailure('invalid_response');
        }
        foreach ($page['items'] ?? [] as $rank => $item) {
            $id = $method === 'search' ? ($item['id']['videoId'] ?? null) : ($item['contentDetails']['videoId'] ?? null);
            if (! is_string($id) || ! preg_match('/^[A-Za-z0-9_-]{11}$/D', $id)) {
                throw new ApiFailure('invalid_response');
            }
            $target = $this->add($run, 'video', $id);
            $run->items()->where('target_id', $target->id)->whereNull('search_rank')->update(['search_rank' => $run->search_hits + $rank + 1]);
        }
    }

    private function add(FetchRun $run, string $kind, string $id): Target
    {
        $target = Target::firstOrCreate(['source' => $run->source, 'kind' => $kind, 'external_id' => $id]);
        RunItem::firstOrCreate(['fetch_run_id' => $run->id, 'target_id' => $target->id]);

        return $target;
    }

    private function hydrate(FetchRun $run): void
    {
        foreach (['video', 'channel'] as $kind) {
            $items = $run->items()->whereIn('status', ['pending', 'failed'])->whereHas('target', fn ($q) => $q->where('kind', $kind))->with('target')->get();
            foreach ($items->chunk(50) as $chunk) {
                try {
                    $response = $this->api->call($kind === 'video' ? 'videos' : 'channels', ['id' => $chunk->pluck('target.external_id')->implode(','), 'maxResults' => 50]);
                    if (! isset($response['items']) || ! is_array($response['items'])) {
                        throw new ApiFailure('invalid_response');
                    }
                    $received = collect($response['items'])->keyBy('id');
                    $fetchedAt = now();
                    foreach ($chunk as $item) {
                        $target = $item->target;
                        $data = $received->get($target->external_id);
                        if ($data === null) {
                            DB::transaction(function () use ($target, $item) {
                                $target->video()->delete();
                                $target->channel()->delete();
                                $target->observations()->delete();
                                $target->update(['last_status' => 'unavailable', 'last_attempt_at' => now()]);
                                $item->update(['status' => 'unavailable', 'error_code' => 'not_returned']);
                            });

                            continue;
                        }
                        DB::transaction(function () use ($run, $target, $data, $fetchedAt, $item, $kind) {
                            $this->store($run, $target, $data, $fetchedAt);
                            $item->update(['status' => 'success', 'error_code' => null]);
                            if ($kind === 'video') {
                                $this->add($run, 'channel', $data['snippet']['channelId']);
                            }
                        });
                    }
                } catch (ApiFailure $e) {
                    foreach ($chunk as $item) {
                        // A malformed later item must not undo already committed successes.
                        if ($item->fresh()->status === 'success') {
                            continue;
                        }
                        $item->update(['status' => 'failed', 'error_code' => $e->reason]);
                        $item->target->update(['last_status' => 'failed', 'last_attempt_at' => now()]);
                    }
                    if (in_array($e->reason, ['key_missing', 'daily_limit', 'quota_exceeded', 'operation_limit', 'run_limit', 'halt_requested', 'expired_run', 'access_denied'], true)) {
                        throw $e;
                    }
                }
            }
        }
    }

    private function store(FetchRun $run, Target $target, array $data, CarbonInterface $fetchedAt): void
    {
        $s = $data['snippet'] ?? [];
        if (! is_string($s['title'] ?? null) || ($target->kind === 'video' && ! preg_match('/^UC[A-Za-z0-9_-]{22}$/D', $s['channelId'] ?? ''))) {
            throw new ApiFailure('invalid_response');
        }
        try {
            $published = isset($s['publishedAt']) ? CarbonImmutable::parse($s['publishedAt'])->utc() : null;
            $duration = isset($data['contentDetails']['duration']) ? new \DateInterval($data['contentDetails']['duration']) : null;
        } catch (\Exception) {
            throw new ApiFailure('invalid_response');
        }
        $stats = $data['statistics'] ?? [];
        $time = $fetchedAt;
        $expires = $time->copy()->addDays(config('youtube.retention_days'));
        $base = ['title' => $s['title'], 'published_at' => $published, 'thumbnail_url' => $this->thumbnail($s), 'view_count' => $this->number($stats['viewCount'] ?? null), 'fetched_at' => $time, 'expires_at' => $expires];
        if ($target->kind === 'video') {
            $base += ['channel_external_id' => $s['channelId'], 'duration_seconds' => $duration ? ($duration->d * 86400 + $duration->h * 3600 + $duration->i * 60 + $duration->s) : null, 'like_count' => $this->number($stats['likeCount'] ?? null), 'comment_count' => $this->number($stats['commentCount'] ?? null), 'broadcast_status' => in_array($s['liveBroadcastContent'] ?? '', ['none', 'live', 'upcoming'], true) ? $s['liveBroadcastContent'] : 'unknown', 'has_live_details' => isset($data['liveStreamingDetails'])];
        } else {
            $base += ['uploads_playlist' => $data['contentDetails']['relatedPlaylists']['uploads'] ?? null, 'subscriber_count' => ! empty($stats['hiddenSubscriberCount']) ? null : $this->number($stats['subscriberCount'] ?? null), 'video_count' => $this->number($stats['videoCount'] ?? null), 'hidden_subscribers' => $stats['hiddenSubscriberCount'] ?? false];
        }
        DB::transaction(function () use ($run, $target, $base, $time, $expires) {
            if (Observation::where('target_id', $target->id)->where('fetch_run_id', $run->id)->exists()) {
                return;
            }
            ($target->kind === 'video' ? Video::class : Channel::class)::updateOrCreate(['target_id' => $target->id], $base);
            Observation::create(['target_id' => $target->id, 'fetch_run_id' => $run->id, 'view_count' => $base['view_count'], 'subscriber_count' => $base['subscriber_count'] ?? null, 'fetched_at' => $time, 'expires_at' => $expires]);
            $target->update(['last_status' => 'success', 'last_attempt_at' => $time]);
        });
    }

    private function number(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }
        if (! preg_match('/^\d{1,18}$/D', (string) $value)) {
            throw new ApiFailure('invalid_response');
        }

        return (int) $value;
    }

    private function thumbnail(array $snippet): ?string
    {
        $url = $snippet['thumbnails']['medium']['url'] ?? $snippet['thumbnails']['default']['url'] ?? null;
        $host = is_string($url) ? parse_url($url, PHP_URL_HOST) : null;

        return is_string($url) && parse_url($url, PHP_URL_SCHEME) === 'https' && in_array($host, ['i.ytimg.com', 'yt3.ggpht.com', 'yt3.googleusercontent.com'], true) ? $url : null;
    }
}
