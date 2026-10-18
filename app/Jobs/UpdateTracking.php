<?php

namespace App\Jobs;

use App\Services\Tracking\Dataset;
use App\Services\Tracking\Updater;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class UpdateTracking implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $dataset, public string $source) {}

    public function handle(Updater $updater): void
    {
        if ($this->dataset !== Dataset::id()) {
            return;
        }
        $updater->run($this->dataset, $this->source);
        if ($updater->hasPending($this->dataset, $this->source)) {
            self::dispatch($this->dataset, $this->source)->onConnection('database')->onQueue('tracking')->delay(1);
        }
    }
}
