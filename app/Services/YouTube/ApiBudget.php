<?php

namespace App\Services\YouTube;

use App\Models\TrackingTarget;
use App\Services\Tracking\Dataset;
use Illuminate\Support\Facades\DB;

class ApiBudget
{
    public function trackingLimit(): int
    {
        return min(config('youtube.daily_tracking_read_calls'), max(0, config('youtube.daily_read_calls') - config('youtube.manual_read_reserve')));
    }

    public function today(string $source): ?object
    {
        return DB::table('api_days')->where('source', $source)->where('day', now('America/Los_Angeles')->toDateString())->first();
    }

    public function trackingStopReason(?object $row): ?string
    {
        if ($row?->blocked) {
            return 'quota_exceeded';
        }
        if (($row?->read_calls ?? 0) >= config('youtube.daily_read_calls')) {
            return 'daily_limit';
        }
        if (($row?->tracking_read_calls ?? 0) >= $this->trackingLimit()) {
            return 'tracking_daily_limit';
        }

        return null;
    }

    public function reservedForTracking(string $source, object $row): int
    {
        $remaining = max(0, $this->trackingLimit() - $row->tracking_read_calls);
        if ($remaining === 0) {
            return 0;
        }
        $due = TrackingTarget::ready(Dataset::id(), $source)->join('targets', 'targets.id', '=', 'tracking_targets.target_id')
            ->selectRaw('targets.kind, count(*) as n')->groupBy('targets.kind')->get();

        return min($remaining, $due->sum(fn ($group) => (int) ceil($group->n / 50)));
    }
}
