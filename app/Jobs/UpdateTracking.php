<?php

namespace App\Jobs;

use App\Models\Target;
use App\Models\TrackingTarget;
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
        $pending = TrackingTarget::where('dataset_id', $this->dataset)->where('state', 'active')->where('next_due_at', '<=', now())->whereIn('target_id', Target::where('source', $this->source)->select('id'))->where(fn ($q) => $q->whereNull('last_attempt_at')->orWhere('last_attempt_at', '<=', now()->subMinutes(5)))->exists();
        if ($pending) {
            self::dispatch($this->dataset, $this->source)->onConnection('database')->onQueue('tracking')->delay(1);
        }
    }
}
