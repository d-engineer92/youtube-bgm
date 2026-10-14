<?php

namespace App\Services\YouTube;

use Illuminate\Support\Facades\DB;

class ApiCaller
{
    private int $calls = 0;

    private float $started;

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
                DB::table('api_days')->insertOrIgnore(['source' => $source, 'day' => $day]);
                $q = DB::table('api_days')->where('source', $source)->where('day', $day);
                $row = $q->lockForUpdate()->first();
                if ($row->blocked || $limit <= $row->$column) {
                    throw new ApiFailure('daily_limit');
                }
                $q->increment($column);
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
            }
        }
    }
}
