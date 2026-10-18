<?php

namespace App\Services\Tracking;

use App\Models\Target;
use App\Models\TrackingTarget;
use App\Services\YouTube\ApiBudget;
use App\Services\YouTube\ApiFailure;
use App\Services\YouTube\Collector;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class Updater
{
    public function hasPending(string $dataset, string $source): bool
    {
        $budget = app(ApiBudget::class);

        return ! $budget->trackingStopReason($budget->today($source))
            && TrackingTarget::ready($dataset, $source)->exists();
    }

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
            if (! $this->hasPending($dataset, $source)) {
                return;
            }
            $due = TrackingTarget::ready($dataset, $source)->orderBy('next_due_at')->orderBy('target_id')->limit(50)->get();
            if ($due->isEmpty()) {
                return;
            }
            foreach ($due as $track) {
                DB::table('tracking_slots')->insertOrIgnore(['dataset_id' => $dataset, 'target_id' => $track->target_id, 'scheduled_for' => $track->next_due_at, 'state' => 'pending']);
                DB::table('tracking_slots')->where('target_id', $track->target_id)->where('scheduled_for', $track->next_due_at)->where('state', 'failed')->update(['state' => 'pending', 'reason' => null]);
                $track->update(['last_attempt_at' => now()]);
            }
            try {
                $run = app(Collector::class)->refreshTracked($due->pluck('target_id')->all());
                $failures = $run->items()->where('status', '!=', 'success')->get();
                foreach ($failures as $item) {
                    $reason = $item->error_code ?? $run->error_code ?? 'worker_error';
                    TrackingTarget::where('target_id', $item->target_id)->update(['reason' => $reason]);
                    DB::table('tracking_slots')->where('target_id', $item->target_id)->where('state', 'pending')->update(['state' => 'failed', 'reason' => $reason]);
                }
            } catch (ApiFailure $e) {
                TrackingTarget::whereIn('id', $due->pluck('id'))->update(['reason' => $e->reason]);
                DB::table('tracking_slots')->whereIn('target_id', $due->pluck('target_id'))->where('state', 'pending')->update(['state' => 'failed', 'reason' => $e->reason]);
            } catch (\Throwable) {
                // Never serialize SQL bindings containing API metadata into logs or failed_jobs.
                TrackingTarget::whereIn('id', $due->pluck('id'))->update(['reason' => 'worker_error']);
                DB::table('tracking_slots')->whereIn('target_id', $due->pluck('target_id'))->where('state', 'pending')->update(['state' => 'failed', 'reason' => 'worker_error']);
                Target::whereIn('id', $due->pluck('target_id'))->update(['last_status' => 'failed', 'last_attempt_at' => now()]);
            }
        } finally {
            app()->forgetInstance('tracking_request');
            $lock->release();
            config(['youtube.mode' => $previous]);
        }
    }
}
