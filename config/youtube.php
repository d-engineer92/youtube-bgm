<?php

return [
    'mode' => env('YOUTUBE_MODE', 'live'),
    'key' => env('YOUTUBE_API_KEY', ''),
    'initial_limit' => max(1, min(200, (int) env('YOUTUBE_INITIAL_LIMIT', 20))),
    'max_items' => max(1, min(1000, (int) env('YOUTUBE_MAX_ITEMS', 200))),
    'timeout' => max(1, min(30, (int) env('YOUTUBE_TIMEOUT', 10))),
    'retries' => max(0, min(3, (int) env('YOUTUBE_RETRIES', 1))),
    'max_calls' => max(1, min(100, (int) env('YOUTUBE_MAX_CALLS_PER_OPERATION', 20))),
    'operation_seconds' => max(1, min(120, (int) env('YOUTUBE_OPERATION_SECONDS', 60))),
    'daily_search_calls' => max(0, (int) env('YOUTUBE_DAILY_SEARCH_CALLS', 20)),
    'daily_read_calls' => max(0, (int) env('YOUTUBE_DAILY_READ_CALLS', 500)),
    'daily_tracking_read_calls' => max(0, (int) env('YOUTUBE_DAILY_TRACKING_READ_CALLS', 100)),
    'manual_read_reserve' => max(0, (int) env('YOUTUBE_MANUAL_READ_RESERVE', 100)),
    'retention_days' => 28,
];
