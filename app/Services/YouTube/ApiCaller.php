<?php

namespace App\Services\YouTube;

use App\Models\Exploration;
use Illuminate\Support\Facades\DB;

class ApiCaller
{
    private int $calls = 0;

    private float $started;

    private ?string $explorationId = null;

    public function setExploration(?string $id): void
    {
        $this->explorationId = $id;
    }

    public function __construct(private Gateway $gateway)
    {
        $this->started = microtime(true);
    }

    public function reset(): void
    {
        $this->calls = 0;
        $this->started = microtime(true);
    }

    public function call(string $method, array $parameters): array
    {
        for ($attempt = 0; ; $attempt++) {
            if ($this->calls >= config('youtube.max_calls') || microtime(true) - $this->started >= config('youtube.operation_seconds')) {
                throw new ApiFailure('operation_limit');
            }
            // Missing credentials do not consume the app's call budget or silently activate the demo.
            if (config('youtube.mode') === 'live' && ! config('youtube.key')) {
                throw new ApiFailure('key_missing');
            }
            $day = now('America/Los_Angeles')->toDateString();
            $source = config('youtube.mode');
            $column = $method === 'search' ? 'search_calls' : 'read_calls';
            $limit = config($method === 'search' ? 'youtube.daily_search_calls' : 'youtube.daily_read_calls');
            DB::transaction(function () use ($day, $source, $column, $limit) {
                $exploration = $this->explorationId ? Exploration::lockForUpdate()->find($this->explorationId) : null;
                if ($this->explorationId) {
                    if (! $exploration || $exploration->source !== $source || $exploration->expires_at <= now()) {
                        throw new ApiFailure('expired_run');
                    }
                    if ($exploration->stop_requested) {
                        throw new ApiFailure('halt_requested');
                    }
                    if ($exploration->getAttribute($column === 'search_calls' ? 'search_limit' : 'read_limit') <= $exploration->$column) {
                        throw new ApiFailure('run_limit');
                    }
                }
                DB::table('api_days')->insertOrIgnore(['source' => $source, 'day' => $day]);
                $q = DB::table('api_days')->where('source', $source)->where('day', $day);
                $row = $q->lockForUpdate()->first();
                if ($row->blocked || $limit <= $row->$column) {
                    throw new ApiFailure('daily_limit');
                }
                $due = DB::table('tracking_targets')->join('targets', 'targets.id', '=', 'tracking_targets.target_id')->where('targets.source', $source)->where('tracking_targets.state', 'active')->where('next_due_at', '<=', now())->selectRaw('targets.kind, count(*) as n')->groupBy('targets.kind')->get();
                $reserved = $due->sum(fn ($group) => (int) ceil($group->n / 50));
                if (! app()->bound('tracking_request') && $reserved > 0 && ($column === 'search_calls' || $row->read_calls + $reserved >= config('youtube.daily_read_calls'))) {
                    throw new ApiFailure('tracking_budget_reserved');
                }
                $q->increment($column);
                if ($exploration) {
                    $exploration->increment($column);
                    $exploration->update(['in_flight' => true, 'heartbeat_at' => now()]);
                }
            });
            $this->calls++;
            try {
                return $this->gateway->fetch($method, $parameters);
            } catch (ApiFailure $e) {
                if ($e->reason === 'quota_exceeded') {
                    DB::table('api_days')->where('source', $source)->where('day', $day)->update(['blocked' => true]);
                }
                if (! $e->retryable || $attempt >= config('youtube.retries')) {
                    throw $e;
                }
                usleep(200000 * (2 ** $attempt));
            } finally {
                if ($this->explorationId) {
                    Exploration::whereKey($this->explorationId)->update(['in_flight' => false, 'heartbeat_at' => now()]);
                }
            }
        }
    }
}
