<?php

namespace App\Jobs;

use App\Models\Exploration;
use App\Services\Discovery\Runner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class CollectExploration implements ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public int $tries = 20;

    public function __construct(public string $explorationId) {}

    public function handle(Runner $runner): void
    {
        $delay = $runner->step($this->explorationId);
        if ($delay !== null) {
            if ($delay > 0 && $this->job) {
                $this->release($delay);
            } else {
                self::dispatch($this->explorationId)->onConnection('database')->delay($delay);
            }
        }
    }

    public function failed(?\Throwable $exception): void
    {
        Exploration::whereKey($this->explorationId)->whereIn('status', ['queued', 'running'])->update(['status' => 'paused', 'stop_reason' => 'worker_error', 'consumption_uncertain' => true]);
    }
}
