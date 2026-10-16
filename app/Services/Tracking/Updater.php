<?php

namespace App\Services\Tracking;

use App\Models\Target;
use App\Models\TrackingTarget;
use App\Services\YouTube\ApiFailure;
use App\Services\YouTube\Collector;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class Updater
{
    public function run(string $dataset, string $source): void
    {
        Dataset::assert($dataset);
        $previous = config('youtube.mode');
        config(['youtube.mode' => $source]);
        $lock = Cache::lock('tracking:'.$dataset.':'.$source, 180);
        if (! $lock->get()) {
            config(['youtube.mode' => $previous]);

            return;
        }
        try {
            app()->instance('tracking_request', true);
            Dataset::heartbeat('worker');
            $due = TrackingTarget::where('dataset_id', $dataset)->where('state', 'active')->where('next_due_at', '<=', now())->whereIn('target_id', Target::where('source', $source)->select('id'))->where(fn ($q) => $q->whereNull('last_attempt_at')->orWhere('last_attempt_at', '<=', now()->subMinutes(5)))->orderBy('next_due_at')->limit(50)->get();
            if ($due->isEmpty()) {
                return;
            }
            foreach ($due as $track) {
                DB::table('tracking_slots')->insertOrIgnore(['dataset_id' => $dataset, 'target_id' => $track->target_id, 'scheduled_for' => $track->next_due_at, 'state' => 'pending']);
                $track->update(['last_attempt_at' => now()]);
            }
            try {
                $run = app(Collector::class)->refreshTracked($due->pluck('target_id')->all());
                $failures = $run->items()->where('status', '!=', 'success')->get();
                foreach ($failures as $item) {
                    $reason = $item->error_code ?? 'not_returned';
                    TrackingTarget::where('target_id', $item->target_id)->update(['reason' => $reason]);
                    DB::table('tracking_slots')->where('target_id', $item->target_id)->where('state', 'pending')->update(['state' => 'failed', 'reason' => $reason]);
                }
            } catch (ApiFailure $e) {
                TrackingTarget::whereIn('id', $due->pluck('id'))->update(['reason' => $e->reason]);
            } catch (\Throwable) {
                // Never serialize SQL bindings containing API metadata into logs or failed_jobs.
                TrackingTarget::whereIn('id', $due->pluck('id'))->update(['reason' => 'worker_error']);
                Target::whereIn('id', $due->pluck('target_id'))->update(['last_status' => 'failed', 'last_attempt_at' => now()]);
            }
        } finally {
            app()->forgetInstance('tracking_request');
            $lock->release();
            config(['youtube.mode' => $previous]);
        }
    }
}
